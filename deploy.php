<?php

// ============================================================
// LOGGING SETUP
// ============================================================
define('LOG_FILE', __DIR__ . '/webhook.log');
define('DEBUG', true); // set to false in production to reduce verbosity

/**
 * Append a message to the log file with a timestamp.
 */
function logStep($message, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

/**
 * Execute a shell command, log everything, and return exit code.
 * @param string $command The exact shell command to run.
 * @param bool   $capture Whether to capture and return output (optional).
 * @return array  [exit_code, output_array]
 */
function runCommand($command, $capture = true)
{
    logStep("Executing: $command", 'CMD');
    $output = [];
    $exitCode = -1;
    exec("$command 2>&1", $output, $exitCode);
    if ($capture) {
        foreach ($output as $line) {
            logStep("  output: $line", 'DEBUG');
        }
    }
    logStep("Command finished with exit code: $exitCode", 'CMD');
    return [$exitCode, $output];
}

// ============================================================
// START DEPLOYMENT
// ============================================================
logStep("=== New deployment request ===");

// Store entire payload to log
$rawPayload = file_get_contents('php://input');
logStep("Raw payload:\n$rawPayload");

// Lock handling
$lock = fopen('/tmp/deploy.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logStep("Could not acquire lock - deployment already in progress", 'ERROR');
    echo "Another deployment in progress";
    exit;
}

// ============================================================
// SECURITY (unchanged)
// ============================================================
$SECRET = "super-secret-token";
if ($_GET['token'] !== $SECRET) {
    logStep("Invalid or missing token", 'ERROR');
    http_response_code(403);
    exit;
}

// ============================================================
// PARSE PAYLOAD
// ============================================================
$payload = json_decode($rawPayload, true);

$namespace = $payload['repository']['namespace'] ?? null;

if ($namespace !== null && $namespace !== 'lucienozandry') {
    logStep("Namespace mismatch: $namespace", 'ERROR');
    exit;
}

if ($namespace === null && !isset($_GET['image'])) {
    logStep("No namespace in payload and no 'image' query parameter provided", 'ERROR');
    exit;
}

$image = null;

// Docker Hub webhook format
if (isset($payload['repository']['repo_name']) && isset($payload['push_data']['tag'])) {
    $image = $payload['repository']['repo_name'] . ':' . ($payload['push_data']['tag'] ?? 'latest');
}

// Manual trigger via ?image=
if (!$image && isset($_GET['image'])) {
    $image = $_GET['image'];
}

if (!$image) {
    logStep("No image determined from payload or query string", 'ERROR');
    http_response_code(400);
    echo "No image provided";
    exit;
}

logStep("Deploying image: $image");

// ============================================================
// DEPLOYMENT CONFIG (unchanged)
// ============================================================
$deployments = [
    "lucienozandry/maboo-api:dev" => [
        "compose_file" => __DIR__ . "/compose/maboo-api-dev.yml"
    ],
    "lucienozandry/maboo-api:latest" => [
        "compose_file" => __DIR__ . "/compose/maboo-api-prod.yml"
    ],
    "lucienozandry/maboo-fe:latest" => [
        "compose_file" => __DIR__ . "/compose/maboo-fe-prod.yml"
    ],
    "lucienozandry/maboo-fe:dev" => [
        "compose_file" => __DIR__ . "/compose/maboo-fe-dev.yml"
    ],
    "lucienozandry/maboo-admin-fe:latest" => [
        "compose_file" => __DIR__ . "/compose/maboo-admin-fe-prod.yml"
    ],
    "lucienozandry/alofo-payment-simulator:maboo" => [
        "compose_file" => __DIR__ . "/compose/payment-simulator.yml"
    ]
];

if (!isset($deployments[$image])) {
    logStep("No deployment config for image: $image", 'ERROR');
    echo "No deployment config for: $image";
    exit;
}

$config = $deployments[$image];
$container = $config['container'];

$composeFile = $config['compose_file'];

echo "Deploying $image via Compose...\n";
logStep("--- Starting Compose deployment steps ---");

// 1. Pull the latest image defined in the compose file
logStep("Pulling latest image for compose file: $composeFile");
list($exitCode, $output) = runCommand("docker compose -f $composeFile pull");
if ($exitCode !== 0) {
    logStep("ERROR: docker compose pull failed.", 'ERROR');
    echo "ERROR: Docker Compose pull failed. Check logs.\n";
    exit(1);
}

// 2. Re-create and start the container seamlessly
logStep("Running docker compose up for: $composeFile");
list($exitCode, $output) = runCommand("docker compose -f $composeFile up -d");
if ($exitCode !== 0) {
    logStep("ERROR: docker compose up failed with exit code $exitCode", 'ERROR');
    echo "ERROR: Docker Compose up failed. Check logs.\n";
    exit(1);
} else {
    logStep("Compose services updated and started successfully.");
}

// 3. Optional verification step remains exactly the same...

sleep(2);
logStep("Checking if container $container is running...");
list($exitCode, $output) = runCommand("docker ps --filter name=$container --format '{{.Names}} {{.Status}}'");
if ($exitCode === 0 && count($output) > 0) {
    logStep("Container status: " . implode(', ', $output));
} else {
    logStep("WARNING: Container $container not found in running state. It may have crashed.", 'WARNING');
    // Grab logs for debugging
    list($exitCode, $logOut) = runCommand("docker logs --tail 20 $container");
    logStep("Last 20 lines of container logs:\n" . implode("\n", $logOut));
}

// ============================================================
// DONE
// ============================================================
logStep("Deployment of $image completed successfully.\n");
echo "\n✅ Done\n";
