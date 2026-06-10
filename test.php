<?php
// ===== Gemini Simple Chatbot =====
// Replace with your Gemini API Key
$apiKey = "AIzaSyB8Me39xV-wX-uvVmvU8ILBdKR5BKzcclY";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userMessage = trim($_POST['message'] ?? '');

    if ($userMessage !== '') {
        // Gemini API Endpoint
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;

        // Request body
        $data = [
            "contents" => [
                ["parts" => [["text" => $userMessage]]]
            ]
        ];

        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        // Execute API request
        $response = curl_exec($ch);
        curl_close($ch);

        // Parse response
        $json = json_decode($response, true);
        $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? 'No response from AI.';
    } else {
        $reply = "Please type a message.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Gemini PHP Chatbot</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f9; margin: 0; padding: 0; }
    .chat-container { max-width: 600px; margin: 60px auto; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; resize: none; }
    button { margin-top: 10px; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
    button:hover { background: #0056b3; }
    .message { margin-top: 20px; padding: 15px; border-radius: 10px; }
    .user { background: #e0f7fa; text-align: right; }
    .bot { background: #e8eaf6; }
  </style>
</head>
<body>
  <div class="chat-container">
    <h2>💬 Gemini Chatbot</h2>
    <form method="POST">
      <textarea name="message" rows="3" placeholder="Type your message..."></textarea>
      <button type="submit">Send</button>
    </form>

    <?php if (!empty($userMessage)): ?>
      <div class="message user"><strong>You:</strong> <?= htmlspecialchars($userMessage) ?></div>
      <div class="message bot"><strong>Gemini:</strong> <?= nl2br(htmlspecialchars($reply)) ?></div>
    <?php endif; ?>
  </div>
</body>
</html>

