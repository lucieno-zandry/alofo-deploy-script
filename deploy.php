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
    "lucienozandry/alofo-api:latest" => [
        "container" => "alofo_api",
        "command" => "docker run --name alofo_api -p 9000:80 \
            -e APP_URL=http://102.16.254.6:9000 \
            -v /etc/docker/alofo/api/dev/storage:/var/www/html/storage \
            -v /etc/docker/alofo/api/dev/.env:/var/www/html/.env \
            -d lucienozandry/alofo-api:latest"
    ],
    "lucienozandry/alofo-fe:latest" => [
        "container" => "alofo_fe",
        "command" => "docker run -d \
            -p 4000:3000 \
            -e API_BASE_URL=http://102.16.254.6:9000 \
            --name alofo_fe \
            lucienozandry/alofo-fe:latest"
    ],
    "lucienozandry/maboo-api:dev" => [
        "container" => "maboo_api_dev",
        "command" => "docker run --name maboo_api_dev -p 8001:80 \
            -e APP_URL=http://102.16.254.6:8001 \
            -v /etc/docker/api/dev/storage:/var/www/html/storage \
            -v /etc/docker/api/dev/.env:/var/www/html/.env \
            -d lucienozandry/maboo-api:dev"
    ],
    "lucienozandry/maboo-api:latest" => [
        "container" => "maboo_api",
        "command" => "docker run --name maboo_api -p 8000:80 \
            -e APP_URL=http://102.16.254.6:8000 \
            -v /etc/docker/api/dev/storage:/var/www/html/storage \
            -v /etc/docker/api/master/.env:/var/www/html/.env \
            -d lucienozandry/maboo-api:latest"
    ],
    "lucienozandry/maboo_fe:latest" => [
        "container" => "maboo_fe",
        "command" => "docker run -d \
            -p 443:3000 \
            -e API_BASE_URL=http://102.16.254.6:8000 \
            --name maboo_fe \
            lucienozandry/maboo_fe:latest"
    ],
    "lucienozandry/maboo_fe:dev" => [
        "container" => "maboo_fe_dev",
        "command" => "docker run -d \
            -p 3000:3000 \
            -e API_BASE_URL=http://102.16.254.6:8001 \
            --name maboo_fe_dev \
            lucienozandry/maboo_fe:dev"
    ],
    "lucienozandry/alofo-backoffice-fe:latest" => [
        "container" => "alofo_backoffice_fe",
        "command" => "docker run -d \
        -p 4500:3000 \
        -e API_BASE_URL=http://102.16.254.6:9000 \
        --name alofo_backoffice_fe \
        lucienozandry/alofo-backoffice-fe:latest"
    ],
    "lucienozandry/alofo-payment-simulator:latest" => [
        "container" => "payment_simulator",
        "command" => "docker run -d \
        -p 5500:80 \
        --name payment_simulator \
        lucienozandry/alofo-payment-simulator:latest"
    ]
];

if (!isset($deployments[$image])) {
    logStep("No deployment config for image: $image", 'ERROR');
    echo "No deployment config for: $image";
    exit;
}

$config = $deployments[$image];
$container = $config['container'];

// ============================================================
// EXECUTION STEPS (with detailed logging)
// ============================================================
echo "Deploying $image...\n";
logStep("--- Starting deployment steps ---");

// 1. Remove existing container
logStep("Removing container: $container");
list($exitCode, $output) = runCommand("docker rm -f $container");
if ($exitCode !== 0) {
    logStep("Warning: Container $container could not be removed (maybe not running)", 'WARNING');
    // Not fatal – we continue
}

// 2. Pull latest image
logStep("Pulling image: $image");
list($exitCode, $output) = runCommand("docker pull $image");
if ($exitCode !== 0) {
    logStep("ERROR: Failed to pull image $image. Exiting.", 'ERROR');
    echo "ERROR: docker pull failed. Check logs.\n";
    exit(1);
}

// 3. Run new container
logStep("Starting container: $container with command: " . $config['command']);
list($exitCode, $output) = runCommand($config['command']);
if ($exitCode !== 0) {
    logStep("ERROR: docker run failed with exit code $exitCode", 'ERROR');
} else {
    logStep("Container $container started successfully (short ID may appear above)");
}

// 4. (Optional) Verify container is actually running after a short delay
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
