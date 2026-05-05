<?php
$page_title = 'Messages';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];
$toId = intval($_GET['to'] ?? 0);

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message'] ?? '');
    $receiver = intval($_POST['receiver_id'] ?? 0);
    if ($msg && $receiver) {
        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?,?,?)")->execute([$userId, $receiver, $msg]);
        $toId = $receiver;
    }
    header("Location: index.php?to=$toId"); exit();
}

// Mark messages as read
if ($toId) {
    $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")->execute([$toId, $userId]);
}

// Helper: render avatar (profile image or initial)
function renderAvatar($user, $size = 40, $fontSize = '1rem') {
    $initial = strtoupper(substr($user['name'], 0, 1));
    if (!empty($user['profile_image'])) {
        $img = htmlspecialchars($user['profile_image']);
        return "<img src=\"../assets/images/profiles/{$img}\" alt=\"" . htmlspecialchars($user['name']) . "\"
                     style=\"width:{$size}px;height:{$size}px;border-radius:50%;object-fit:cover;flex-shrink:0;\">";
    }
    return "<div style=\"width:{$size}px;height:{$size}px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:{$fontSize};flex-shrink:0;\">{$initial}</div>";
}

// Get conversation partners (with profile_image)
$contacts = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.role, u.profile_image,
        (SELECT message FROM messages WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT created_at FROM messages WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=? AND is_read=0) as unread
    FROM users u
    JOIN messages m ON (m.sender_id=u.id AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=u.id)
    WHERE u.id != ?
    ORDER BY last_time DESC
");
$contacts->execute([$userId,$userId,$userId,$userId,$userId,$userId,$userId,$userId]);
$contacts = $contacts->fetchAll();

// Get current conversation
$conversation = [];
$currentContact = null;
if ($toId) {
    $conversation = $pdo->prepare("
        SELECT m.*, u.name as sender_name, u.profile_image as sender_profile_image
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
        ORDER BY m.created_at ASC
    ");
    $conversation->execute([$userId, $toId, $toId, $userId]);
    $conversation = $conversation->fetchAll();

    $currentContact = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $currentContact->execute([$toId]);
    $currentContact = $currentContact->fetch();
}

// Current logged-in user profile image
$meRow = $pdo->prepare("SELECT profile_image, name FROM users WHERE id=?");
$meRow->execute([$userId]);
$meUser = $meRow->fetch();
?>

<div style="background:var(--bg);min-height:100vh;padding-bottom:2rem;">
    <div class="page-header">
        <div class="container">
            <h1><i class="fa-solid fa-comments text-green me-2"></i>Messages</h1>
        </div>
    </div>

    <div class="container">
        <div class="chat-container">
            <!-- Contact List -->
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <i class="fa-solid fa-comments text-green me-2"></i>Conversations
                </div>
                <?php if (empty($contacts)): ?>
                <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.85rem;">
                    No conversations yet.<br>Start by ordering a product.
                </div>
                <?php else: foreach ($contacts as $c): ?>
                <a href="?to=<?= $c['id'] ?>" class="chat-contact <?= $toId === intval($c['id']) ? 'active' : '' ?>"
                   style="display:flex;align-items:center;gap:10px;padding:0.85rem 1rem;text-decoration:none;border-bottom:1px solid var(--border);transition:background 0.15s;<?= $toId === intval($c['id']) ? 'background:var(--pale-green);' : '' ?>">
                    <!-- Profile picture or initial -->
                    <div style="position:relative;flex-shrink:0;">
                        <?php if (!empty($c['profile_image'])): ?>
                            <img src="../assets/images/profiles/<?= htmlspecialchars($c['profile_image']) ?>"
                                 alt="<?= htmlspecialchars($c['name']) ?>"
                                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.1rem;">
                                <?= strtoupper(substr($c['name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <!-- Role badge -->
                        <span style="position:absolute;bottom:-2px;right:-2px;font-size:0.6rem;background:white;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,0.15);">
                            <?= $c['role'] === 'farmer' ? '🌾' : '🛒' ?>
                        </span>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div class="chat-contact-name" style="font-weight:800;color:var(--text);font-size:0.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px;"><?= sanitize($c['name']) ?></div>
                            <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;">
                                <?php if ($c['unread'] > 0): ?>
                                <span style="background:var(--primary);color:white;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:800;"><?= $c['unread'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="chat-contact-preview" style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $c['last_msg'] ? sanitize(substr($c['last_msg'],0,38)).'…' : 'No messages yet' ?></div>
                        <div style="font-size:0.7rem;color:var(--text-muted);"><?= $c['last_time'] ? date('M j', strtotime($c['last_time'])) : '' ?></div>
                    </div>
                </a>
                <?php endforeach; endif; ?>
            </div>

            <!-- Chat Main -->
            <div class="chat-main">
                <?php if ($currentContact): ?>
                <!-- Chat Header -->
                <div class="chat-header" style="display:flex;align-items:center;gap:12px;padding:1rem 1.2rem;border-bottom:1px solid var(--border);background:white;">
                    <div style="position:relative;flex-shrink:0;">
                        <?php if (!empty($currentContact['profile_image'])): ?>
                            <img src="../assets/images/profiles/<?= htmlspecialchars($currentContact['profile_image']) ?>"
                                 alt="<?= htmlspecialchars($currentContact['name']) ?>"
                                 style="width:46px;height:46px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.15rem;">
                                <?= strtoupper(substr($currentContact['name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <span style="position:absolute;bottom:0;right:0;width:12px;height:12px;background:#22C55E;border-radius:50%;border:2px solid white;"></span>
                    </div>
                    <div>
                        <div style="font-weight:800;color:var(--text);"><?= sanitize($currentContact['name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);">📍 <?= sanitize($currentContact['location'] ?? 'Mindanao') ?> · <?= ucfirst($currentContact['role']) ?></div>
                    </div>
                </div>

                <!-- Messages -->
               <div class="chat-messages" id="chatMessages" style="flex:1;overflow-y:auto;padding:1.2rem 1.5rem;display:flex;flex-direction:column;gap:0.75rem;overflow-x:hidden;box-sizing:border-box;width:100%;">
                    <?php if (empty($conversation)): ?>
                    <div style="text-align:center;color:var(--text-muted);font-size:0.85rem;padding:2rem;">
                        Start the conversation! 👋
                    </div>
                    <?php else:
                        $prevDate = null;
                        foreach ($conversation as $msg):
                            $msgDate = date('M j, Y', strtotime($msg['created_at']));
                            $isMine = $msg['sender_id'] == $userId;
                            if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
                            <div style="text-align:center;font-size:0.72rem;color:var(--text-muted);font-weight:700;padding:0.5rem 0;"><?= $msgDate ?></div>
                        <?php endif; ?>

<div style="display:flex;align-items:flex-end;gap:8px;width:100%;<?= $isMine ? 'flex-direction:row-reverse;margin-left:auto;' : 'flex-direction:row;' ?>">                            <!-- Avatar per message -->
                            <?php if (!$isMine): ?>
                            <div style="flex-shrink:0;margin-bottom:2px;">
                                <?php if (!empty($msg['sender_profile_image'])): ?>
                                    <img src="../assets/images/profiles/<?= htmlspecialchars($msg['sender_profile_image']) ?>"
                                         alt="<?= htmlspecialchars($msg['sender_name']) ?>"
                                         style="width:30px;height:30px;border-radius:50%;object-fit:cover;"
                                         title="<?= htmlspecialchars($msg['sender_name']) ?>">
                                <?php else: ?>
                                    <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:0.7rem;"
                                         title="<?= htmlspecialchars($msg['sender_name']) ?>">
                                        <?= strtoupper(substr($msg['sender_name'],0,1)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <!-- My own avatar on the right -->
                            <div style="flex-shrink:0;margin-bottom:2px;">
                                <?php if (!empty($meUser['profile_image'])): ?>
                                    <img src="../assets/images/profiles/<?= htmlspecialchars($meUser['profile_image']) ?>"
                                         alt="You"
                                         style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:0.7rem;">
                                        <?= strtoupper(substr($meUser['name'],0,1)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                           <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;max-width:55%;min-width:0;">
                                <?php if (!$isMine): ?>
                                <div style="font-size:0.68rem;color:var(--text-muted);margin-bottom:3px;font-weight:700;"><?= sanitize($msg['sender_name']) ?></div>
                                <?php endif; ?>
                                <div class="chat-bubble <?= $isMine ? 'sent' : 'received' ?>">
                                    <?= nl2br(sanitize($msg['message'])) ?>
                                    <div class="chat-bubble-time"><?= date('g:ia', strtotime($msg['created_at'])) ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                </div>

               <div class="chat-input-area" style="position:relative;z-index:10;background:white;border-top:1px solid var(--border);">
    <input type="hidden" id="receiverId" value="<?= $toId ?>">
    <input type="text" id="messageInput" class="chat-input" placeholder="Type your message..." autocomplete="off">
    <button id="sendBtn" class="btn-green" style="border-radius:50%;width:44px;height:44px;padding:0;justify-content:center;flex-shrink:0;">
        <i class="fa-solid fa-paper-plane"></i>
    </button>
</div>

                <?php else: ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--text-muted);">
                    <div>
                        <div style="font-size:3rem;margin-bottom:1rem;">💬</div>
                        <div style="font-weight:700;">Select a conversation to start messaging</div>
                        <div style="font-size:0.85rem;margin-top:0.5rem;">Messages appear here</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function scrollToBottom() {
    const el = document.getElementById('chatMessages');
    if (el) el.scrollTop = el.scrollHeight;
}
scrollToBottom();
window.addEventListener('load', scrollToBottom);

const sendBtn = document.getElementById('sendBtn');
const msgInput = document.getElementById('messageInput');
const receiverId = document.getElementById('receiverId');

function sendMessage() {
    const msg = msgInput.value.trim();
    if (!msg || !receiverId) return;

    // Optimistically append bubble immediately
    const chatMessages = document.getElementById('chatMessages');
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', hour12:true}).toLowerCase();

    <?php if (!empty($meUser['profile_image'])): ?>
    const avatarHtml = `<img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($meUser['profile_image']) ?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">`;
    <?php else: ?>
    const avatarHtml = `<div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:0.7rem;"><?= strtoupper(substr($meUser['name'],0,1)) ?></div>`;
    <?php endif; ?>

    const bubble = document.createElement('div');
    bubble.style.cssText = 'display:flex;align-items:flex-end;gap:8px;width:100%;flex-direction:row-reverse;';
    bubble.innerHTML = `
        <div style="flex-shrink:0;margin-bottom:2px;">${avatarHtml}</div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;max-width:55%;min-width:0;">
            <div class="chat-bubble sent">
${msg.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}                <div class="chat-bubble-time">${timeStr}</div>
            </div>
        </div>`;
    chatMessages.appendChild(bubble);
    msgInput.value = '';
    scrollToBottom();

    // Send via AJAX
    fetch('index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `message=${encodeURIComponent(msg)}&receiver_id=${receiverId.value}&ajax=1`
    });
}

sendBtn.addEventListener('click', sendMessage);
msgInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

// Poll for new messages every 3 seconds
let lastMsgCount = document.querySelectorAll('.chat-bubble').length;
setInterval(() => {
    fetch(`poll.php?to=<?= $toId ?>&after=<?= !empty($conversation) ? end($conversation)['id'] : 0 ?>`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) return;
            const chatMessages = document.getElementById('chatMessages');
            data.forEach(m => {
                <?php if (!empty($currentContact['profile_image'])): ?>
                const theirAvatar = `<img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($currentContact['profile_image']) ?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">`;
                <?php else: ?>
                const theirAvatar = `<div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:0.7rem;"><?= $currentContact ? strtoupper(substr($currentContact['name'],0,1)) : '' ?></div>`;
                <?php endif; ?>
                const bubble = document.createElement('div');
                bubble.style.cssText = 'display:flex;align-items:flex-end;gap:8px;width:100%;flex-direction:row;';
                bubble.innerHTML = `
                    <div style="flex-shrink:0;margin-bottom:2px;">${theirAvatar}</div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;max-width:55%;min-width:0;">
                        <div class="chat-bubble received">
                            ${m.message.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#039;/g,"'").replace(/\n/g,'<br>')}
                            <div class="chat-bubble-time">${m.time}</div>
                        </div>
                    </div>`;
                chatMessages.appendChild(bubble);
            });
            scrollToBottom();
        }).catch(() => {});
}, 3000);
</script>

<style>
.chat-bubble.sent {
    background: linear-gradient(135deg, #1B5E20, #2E7D32) !important;
    color: white !important;
    border-radius: 18px 18px 4px 18px !important;
    margin-left: auto;
}
.chat-bubble.received {
    background: white !important;
    color: var(--text) !important;
    border-radius: 18px 18px 18px 4px !important;
    border: 1px solid var(--border);
}
.chat-bubble {
    padding: 0.6rem 0.9rem;
    font-size: 0.88rem;
    line-height: 1.5;
    max-width: 100%;
    word-break: break-word;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.chat-bubble-time {
    font-size: 0.65rem;
    opacity: 0.65;
    margin-top: 4px;
    text-align: right;
}
.chat-input-area {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0.85rem 1.2rem;
    background: white;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}
.chat-input {
    flex: 1;
    border: 2px solid var(--border);
    border-radius: 25px;
    padding: 0.6rem 1.1rem;
    font-size: 0.88rem;
    outline: none;
    transition: border 0.2s;
}
.chat-input:focus {
    border-color: var(--primary);
}
.chat-container {
    display: flex;
    height: 520px;
    background: white;
    border-radius: 18px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid var(--border);
}
.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>