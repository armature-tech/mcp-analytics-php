#!/usr/bin/env node
import assert from "node:assert/strict";
import { cp, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { spawnSync } from "node:child_process";
import { tmpdir } from "node:os";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const canaryDirectory = resolve(dirname(fileURLToPath(import.meta.url)));
const arg = name => {
  const index = process.argv.indexOf(name);
  if (index < 0 || !process.argv[index + 1]) throw new Error(`missing ${name}`);
  return process.argv[index + 1];
};
const required = name => {
  const value = process.env[name];
  if (!value) throw new Error(`missing ${name}`);
  return value;
};
const artifact = resolve(arg("--artifact"));
const marker = arg("--marker");
const region = required("AWS_REGION");
const expectedAccount = required("EXPECTED_AWS_ACCOUNT_ID");
const repositoryName = required("SDK_CANARY_ECR_REPOSITORY");
const serviceArn = required("SDK_CANARY_APP_RUNNER_SERVICE_ARN");
const stableUrl = required("SDK_CANARY_MCP_URL").replace(/\/$/, "");
const platformUrl = required("SDK_CANARY_PLATFORM_URL").replace(/\/$/, "");
const ingestSecretArn = required("SDK_CANARY_INGEST_SECRET_ARN");
const allowedHost = new URL(stableUrl).hostname;

assert.ok(marker.startsWith("sdk-canary/php/"), "deployment marker must be a PHP SDK canary marker");
assert.equal(new URL(stableUrl).pathname.replace(/\/$/, ""), "/mcp", "stable canary URL must end in /mcp");

const run = (command, args, options = {}) => {
  const result = spawnSync(command, args, {
    encoding: "utf8",
    stdio: options.capture ? ["pipe", "pipe", "pipe"] : "inherit",
    input: options.input,
    ...options,
  });
  if (result.status !== 0) {
    const detail = options.capture ? (result.stderr || result.stdout || "").trim() : "";
    throw new Error(`${basename(command)} failed with exit ${result.status}${detail ? `: ${detail}` : ""}`);
  }
  return result.stdout || "";
};
const awsJson = args => JSON.parse(run("aws", [...args, "--region", region, "--output", "json", "--no-cli-pager"], { capture: true }));
const sleep = milliseconds => new Promise(resolvePromise => setTimeout(resolvePromise, milliseconds));

async function waitForOperation(operationId) {
  const deadline = Date.now() + 15 * 60_000;
  while (Date.now() < deadline) {
    const body = awsJson(["apprunner", "list-operations", "--service-arn", serviceArn, "--max-results", "20"]);
    const operation = body.OperationSummaryList?.find(candidate => candidate.Id === operationId);
    if (operation?.Status === "SUCCEEDED") return;
    if (["FAILED", "ROLLBACK_FAILED", "ROLLBACK_SUCCEEDED"].includes(operation?.Status)) {
      throw new Error(`App Runner operation ${operationId} ended as ${operation.Status}`);
    }
    await sleep(10_000);
  }
  throw new Error(`App Runner operation ${operationId} did not complete within 15 minutes`);
}

async function updateService(sourceConfiguration, requestPath) {
  await writeFile(requestPath, `${JSON.stringify({
    ServiceArn: serviceArn,
    SourceConfiguration: sourceConfiguration,
  }, null, 2)}\n`, { mode: 0o600 });
  const result = awsJson(["apprunner", "update-service", "--cli-input-json", `file://${requestPath}`]);
  assert.ok(result.OperationId, "App Runner update did not return an operation id");
  await waitForOperation(result.OperationId);
}

const workspace = await mkdtemp(join(tmpdir(), "sdk-canary-php-app-runner-"));
let oldSource;
let newSource;
try {
  const identity = awsJson(["sts", "get-caller-identity"]);
  assert.equal(identity.Account, expectedAccount, "AWS role resolved to the wrong account");

  const repository = awsJson(["ecr", "describe-repositories", "--repository-names", repositoryName])
    .repositories?.[0];
  assert.ok(repository?.repositoryUri, `ECR repository ${repositoryName} does not exist`);

  const current = awsJson(["apprunner", "describe-service", "--service-arn", serviceArn]).Service;
  assert.equal(current?.Status, "RUNNING", "App Runner canary must be running before deployment");
  const autoScalingArn = current.AutoScalingConfigurationSummary?.AutoScalingConfigurationArn;
  assert.ok(autoScalingArn, "App Runner canary has no auto-scaling configuration");
  const autoScaling = awsJson([
    "apprunner",
    "describe-auto-scaling-configuration",
    "--auto-scaling-configuration-arn",
    autoScalingArn,
  ]).AutoScalingConfiguration;
  assert.equal(autoScaling?.MinSize, 1, "PHP canary must keep exactly one minimum instance");
  assert.equal(autoScaling?.MaxSize, 1, "PHP canary must keep exactly one maximum instance");
  oldSource = current.SourceConfiguration;
  assert.equal(oldSource?.ImageRepository?.ImageRepositoryType, "ECR", "App Runner canary must use private ECR");
  assert.ok(oldSource?.AuthenticationConfiguration?.AccessRoleArn, "App Runner ECR access role is missing");

  const serviceHost = String(current.ServiceUrl || "").toLowerCase();
  assert.ok(
    allowedHost === serviceHost || allowedHost.endsWith(`.${serviceHost}`),
    `SDK_CANARY_MCP_URL host ${allowedHost} does not belong to the configured App Runner service`,
  );

  await cp(join(canaryDirectory, "Dockerfile"), join(workspace, "Dockerfile"));
  await cp(join(canaryDirectory, "Caddyfile"), join(workspace, "Caddyfile"));
  await cp(join(canaryDirectory, "public"), join(workspace, "public"), { recursive: true });
  await cp(artifact, join(workspace, "candidate.zip"));

  const tag = marker.replace(/[^A-Za-z0-9_.-]+/g, "-").slice(-120);
  const imageTag = `${repository.repositoryUri}:${tag}`;
  const password = run("aws", ["ecr", "get-login-password", "--region", region], { capture: true });
  run("docker", ["login", "--username", "AWS", "--password-stdin", repository.repositoryUri.split("/")[0]], {
    capture: true,
    input: password,
  });
  run("docker", ["build", "--pull", "--no-cache", "--tag", imageTag, workspace]);
  run("docker", ["push", imageTag]);

  const image = awsJson([
    "ecr",
    "describe-images",
    "--repository-name",
    repositoryName,
    "--image-ids",
    `imageTag=${tag}`,
  ]).imageDetails?.[0];
  assert.match(image?.imageDigest || "", /^sha256:[0-9a-f]{64}$/);
  const imageIdentifier = `${repository.repositoryUri}@${image.imageDigest}`;

  newSource = {
    ...oldSource,
    AutoDeploymentsEnabled: false,
    ImageRepository: {
      ...oldSource.ImageRepository,
      ImageIdentifier: imageIdentifier,
      ImageRepositoryType: "ECR",
      ImageConfiguration: {
        Port: "8080",
        RuntimeEnvironmentVariables: {
          SDK_CANARY_ALLOWED_HOST: allowedHost,
          SDK_CANARY_DEPLOYMENT: marker,
          SDK_CANARY_PLATFORM_URL: platformUrl,
        },
        RuntimeEnvironmentSecrets: {
          SDK_CANARY_INGEST_KEY: ingestSecretArn,
        },
      },
    },
  };

  await updateService(newSource, join(workspace, "deploy.json"));
  try {
    run(process.execPath, [
      join(canaryDirectory, "mcp-http-smoke.mjs"),
      "--url",
      stableUrl,
      "--intent",
      `${marker}/protocol-stable`,
      "--deployment",
      marker,
    ]);
  } catch (error) {
    await updateService(oldSource, join(workspace, "rollback.json"));
    throw new Error(`PHP canary smoke test failed; restored ${oldSource.ImageRepository.ImageIdentifier}`, {
      cause: error,
    });
  }

  const summary = [
    "### PHP SDK App Runner deployment",
    "",
    `- Candidate: \`${imageIdentifier}\``,
    `- Previous: \`${oldSource.ImageRepository.ImageIdentifier}\``,
    `- Endpoint: ${stableUrl}`,
    "",
  ].join("\n");
  if (process.env.GITHUB_STEP_SUMMARY) {
    await writeFile(process.env.GITHUB_STEP_SUMMARY, summary, { flag: "a" });
  }
  if (process.env.GITHUB_OUTPUT) {
    await writeFile(process.env.GITHUB_OUTPUT, [
      `image_identifier=${imageIdentifier}`,
      `previous_image_identifier=${oldSource.ImageRepository.ImageIdentifier}`,
      `stable_url=${stableUrl}`,
      "",
    ].join("\n"), { flag: "a" });
  }
  console.log(`deployed exact PHP candidate ${imageIdentifier} to ${stableUrl}`);
} finally {
  await rm(workspace, { recursive: true, force: true });
}
