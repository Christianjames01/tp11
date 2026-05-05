<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);

if (!$body) {
    echo json_encode(['error' => 'No input received']);
    exit;
}

$messages = [];
foreach ($body['messages'] ?? [] as $msg) {
    $content = $msg['content'];
    if (is_array($content)) {
        $content = implode(' ', array_map(fn($b) => $b['text'] ?? '', $content));
    }
    $messages[] = ['role' => $msg['role'], 'content' => $content];
}

// ✏️ CHANGE 1: Groq body → use Groq's params
$groqBody = [
    'model'                 => 'llama-3.3-70b-versatile',  // keep as-is
    'messages'              => $messages,
    'max_completion_tokens' => 1000,                        // keep as-is
];

// ✏️ CHANGE 2: keep Groq URL
$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        // ✏️ CHANGE 3: use GROQ_API_KEY (not ANTHROPIC)
        'Authorization: Bearer ' . getenv('GROQ_API_KEY'),
    ],
    CURLOPT_POSTFIELDS => json_encode($groqBody)
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['error' => 'cURL error: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    echo json_encode([
        'error'    => 'HTTP ' . $httpCode,
        'response' => $response,
        'sent'     => $groqBody
    ]);
    exit;
}

echo json_encode([
    'content' => [
        ['type' => 'text', 'text' => $data['choices'][0]['message']['content'] ?? 'No response.']
    ]
]);