<?php

file_put_contents(
    __DIR__ . '/webhook.log',
    date('c') . "\n" . file_get_contents('php://input') . "\n\n",
    FILE_APPEND
);

$lock = fopen('/tmp/deploy.lock', 'c');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another deployment in progress";
    exit;
}

// 🔐 OPTIONAL: simple secret protection
$SECRET = "super-secret-token";
$headers = getallheaders();

if (!isset($headers['X-Webhook-Token']) || $headers['X-Webhook-Token'] !== $SECRET) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// 📥 Get payload (Docker Hub / GitHub style)
$payload = json_decode(file_get_contents('php://input'), true);

// Try to extract image name
$image = null;

// Docker Hub webhook format
if (isset($payload['repository']['repo_name']) && isset($payload['push_data']['tag'])) {
    $image = $payload['repository']['repo_name'] . ':' . $payload['push_data']['tag'];
}

// Fallback (manual trigger)
if (!$image && isset($_GET['image'])) {
    $image = $_GET['image'];
}

if (!$image) {
    http_response_code(400);
    echo "No image provided";
    exit;
}

// 🧠 Define your deployments here
$deployments = [

    // ================= ALOFO =================
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
            -e VITE_API_BASE_URL=http://102.16.254.6:9000 \
            --name alofo_fe \
            lucienozandry/alofo-fe:latest"
    ],

    // ================= MABOO =================
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
            -e VITE_API_BASE_URL=http://102.16.254.6:8000 \
            --name maboo_fe \
            lucienozandry/maboo_fe:latest"
    ],

    "lucienozandry/maboo_fe:dev" => [
        "container" => "maboo_fe_dev",
        "command" => "docker run -d \
            -p 3000:3000 \
            -e VITE_API_BASE_URL=http://102.16.254.6:8001 \
            --name maboo_fe_dev \
            lucienozandry/maboo_fe:dev"
    ],
];

// ❌ Unknown image
if (!isset($deployments[$image])) {
    echo "No deployment config for: $image";
    exit;
}

$config = $deployments[$image];
$container = $config['container'];

echo "Deploying $image...\n";

// 🧹 Stop & remove existing container (if exists)
exec("docker rm -f $container 2>&1", $output1);

// 📥 Pull latest image
exec("docker pull $image 2>&1", $output2);

// 🚀 Run container
exec($config['command'] . " 2>&1", $output3);

// 🧾 Output logs
echo "\n--- REMOVE ---\n" . implode("\n", $output1);
echo "\n--- PULL ---\n" . implode("\n", $output2);
echo "\n--- RUN ---\n" . implode("\n", $output3);

echo "\n✅ Done\n";
