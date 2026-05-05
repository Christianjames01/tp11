<?php
$page_title = 'Messages';
$hide_navbar = true;
require_once __DIR__ . '/../includes/header.php';
requireRole('rider');
$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$toId   = intval($_GET['to'] ?? 0);

// Handle send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg      = trim($_POST['message'] ?? '');
    $receiver = intval($_POST['receiver_id'] ?? 0);
    if ($msg && $receiver) {
        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?,?,?)")
            ->execute([$userId, $receiver, $msg]);
        $toId = $receiver;
    }
    header("Location: messages.php?to=$toId"); exit();
}

// Mark as read
if ($toId) {
    $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")
        ->execute([$toId, $userId]);
}

// Get contacts — only buyers/farmers from assigned orders
$contacts = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.role, u.profile_image,
        (SELECT message FROM messages
         WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id)
         ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT created_at FROM messages
         WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id)
         ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=? AND is_read=0) as unread
    FROM users u
    WHERE u.id IN (
        SELECT buyer_id  FROM orders WHERE rider_id = ?
        UNION
        SELECT farmer_id FROM orders WHERE rider_id = ?
    )
    ORDER BY last_time DESC
");
$contacts->execute([$userId,$userId,$userId,$userId,$userId,$userId,$userId]);
$contacts = $contacts->fetchAll();

// Current conversation
$conversation   = [];
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

$meRow = $pdo->prepare("SELECT profile_image, name FROM users WHERE id=?");
$meRow->execute([$userId]);
$meUser = $meRow->fetch();

// Fetch rider profile (for header)
$rider = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$rider->execute([$userId]);
$rider = $rider->fetch();

// Pickup ready count (for header badge)
$pickupReady = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = 'confirmed'");
$pickupReady->execute([$userId]);
$pickupReady = $pickupReady->fetchColumn();
?>

<style>
:root {
    --rider-primary: #1B5E20;
    --rider-accent:  #4CAF50;
    --rider-orange:  #F97316;
    --rider-blue:    #3B82F6;
    --rider-bg:      #F0F7F0;
}

.rider-msg-page { background: var(--rider-bg); min-height: 100vh; padding-bottom: 2rem; }

.chat-container {
    display: flex;
    height: 560px;
    background: white;
    border-radius: 18px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    overflow: hidden;
    border: 1px solid var(--border);
}
.chat-sidebar {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.chat-sidebar-header {
    padding: .9rem 1rem;
    font-weight: 800;
    font-size: .85rem;
    border-bottom: 1px solid var(--border);
    background: var(--pale-green);
    color: var(--primary);
    flex-shrink: 0;
}
.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.2rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
    overflow-x: hidden;
    box-sizing: border-box;
    width: 100%;
}
.chat-input-area {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: .85rem 1.2rem;
    background: white;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}
.chat-input {
    flex: 1;
    border: 2px solid var(--border);
    border-radius: 25px;
    padding: .6rem 1.1rem;
    font-size: .88rem;
    outline: none;
    transition: border .2s;
    font-family: inherit;
}
.chat-input:focus { border-color: var(--primary); }

.chat-bubble {
    padding: .6rem .9rem;
    font-size: .88rem;
    line-height: 1.5;
    max-width: 100%;
    word-break: break-word;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
.chat-bubble.sent {
    background: linear-gradient(135deg,#1B5E20,#2E7D32) !important;
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
.chat-bubble-time {
    font-size: .65rem;
    opacity: .65;
    margin-top: 4px;
    text-align: right;
}

.role-badge { font-size: .6rem; padding: 1px 6px; border-radius: 99px; font-weight: 800; }
.role-farmer { background: #DCFCE7; color: #16A34A; }
.role-buyer  { background: #DBEAFE; color: #1D4ED8; }

/* Blink animation for pickup badge */
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }
.blink { animation: blink 1.4s ease-in-out infinite; }
</style>

<div class="rider-msg-page">

    <!-- ═══════════ HEADER (matches dashboard/pickup/orders) ═══════════ -->
    <div style="background:linear-gradient(135deg,#0D3B13 0%,#1B5E20 45%,#2E7D32 100%);padding:1.5rem 0;position:relative;overflow:hidden;">
        <!-- Decorative orbs -->
        <div style="position:absolute;top:-60px;right:-60px;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.03);pointer-events:none;"></div>
        <div style="position:absolute;bottom:-80px;left:20%;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.025);pointer-events:none;"></div>

        <div class="container" style="position:relative;">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">

                <!-- Left: Avatar + Title -->
                <div style="display:flex;align-items:center;gap:14px;">
                    <?php if (!empty($rider['profile_image'])): ?>
                        <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($rider['profile_image']) ?>"
                             style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);flex-shrink:0;">
                    <?php else: ?>
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;border:3px solid rgba(255,255,255,.25);flex-shrink:0;">
                            <?= strtoupper(substr($rider['name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:white;line-height:1.1;">
                            <i class="fa-solid fa-comments me-2" style="color:#86EFAC;font-size:1.2rem;"></i>Messages
                        </div>
                        <div style="font-size:.82rem;color:rgba(255,255,255,.65);margin-top:.2rem;">
                            🛵 <?= sanitize($rider['name']) ?> · <?= sanitize($rider['location'] ?? 'Mindanao') ?>
                        </div>
                        <?php if ($pickupReady > 0): ?>
                        <div style="margin-top:.5rem;">
                            <span style="background:rgba(249,115,22,.25);border:1px solid rgba(249,115,22,.4);border-radius:99px;padding:.3rem .85rem;font-size:.72rem;font-weight:600;color:white;display:inline-flex;align-items:center;gap:5px;" class="blink">
                                🔥 <?= $pickupReady ?> pickup<?= $pickupReady > 1 ? 's' : '' ?> waiting
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Nav -->
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-gauge"></i> Dashboard
                    </a>
                    <a href="pickup.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-box-open"></i> Pickup
                    </a>
                    <a href="orders.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);">
                        <i class="fa-solid fa-list"></i> All Orders
                    </a>
                    <a href="messages.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);">
                        <i class="fa-solid fa-comments"></i> Messages
                    </a>
                    <a href="<?= BASE_URL ?>/auth/logout.php" style="display:flex;align-items:center;gap:6px;padding:.5rem 1rem;color:white;font-size:.8rem;font-weight:700;text-decoration:none;border-radius:10px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>

            </div>
        </div>
    </div>

    <div class="container" style="padding-top:1.5rem;">
        <div class="chat-container">

            <!-- Sidebar -->
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <i class="fa-solid fa-comments me-1"></i> Conversations
                </div>
                <?php if (empty($contacts)): ?>
                <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.82rem;">
                    No conversations yet.<br>Message a buyer or farmer from your orders.
                </div>
                <?php else: foreach ($contacts as $c): ?>
                <a href="?to=<?= $c['id'] ?>"
                   style="display:flex;align-items:center;gap:10px;padding:.85rem 1rem;text-decoration:none;border-bottom:1px solid var(--border);transition:background .15s;<?= $toId === intval($c['id']) ? 'background:var(--pale-green);' : '' ?>">
                    <div style="position:relative;flex-shrink:0;">
                        <?php if (!empty($c['profile_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($c['profile_image']) ?>"
                                 style="width:42px;height:42px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1rem;">
                                <?= strtoupper(substr($c['name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <span style="position:absolute;bottom:-2px;right:-2px;font-size:.55rem;background:white;border-radius:50%;width:15px;height:15px;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,.15);">
                            <?= $c['role'] === 'farmer' ? '🌾' : '🛒' ?>
                        </span>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div style="font-weight:800;color:var(--text);font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;"><?= sanitize($c['name']) ?></div>
                            <?php if ($c['unread'] > 0): ?>
                            <span style="background:var(--primary);color:white;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:800;flex-shrink:0;"><?= $c['unread'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:.72rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= $c['last_msg'] ? sanitize(substr($c['last_msg'],0,30)).'…' : 'No messages yet' ?>
                        </div>
                        <div style="font-size:.65rem;color:var(--text-muted);"><?= $c['last_time'] ? date('M j', strtotime($c['last_time'])) : '' ?></div>
                    </div>
                </a>
                <?php endforeach; endif; ?>
            </div>

            <!-- Chat Main -->
            <div class="chat-main">
                <?php if ($currentContact): ?>

                <!-- Chat Header -->
                <div style="display:flex;align-items:center;gap:12px;padding:1rem 1.2rem;border-bottom:1px solid var(--border);background:white;flex-shrink:0;">
                    <div style="position:relative;flex-shrink:0;">
                        <?php if (!empty($currentContact['profile_image'])): ?>
                            <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($currentContact['profile_image']) ?>"
                                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.1rem;">
                                <?= strtoupper(substr($currentContact['name'],0,1)) ?>
                            </div>
                        <?php endif; ?>
                        <span style="position:absolute;bottom:0;right:0;width:11px;height:11px;background:#22C55E;border-radius:50%;border:2px solid white;"></span>
                    </div>
                    <div>
                        <div style="font-weight:800;color:var(--text);"><?= sanitize($currentContact['name']) ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted);">
                            <?= $currentContact['role'] === 'farmer' ? '🌾 Farmer' : '🛒 Buyer' ?>
                            · 📍 <?= sanitize($currentContact['location'] ?? 'Mindanao') ?>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($conversation)): ?>
                    <div style="text-align:center;color:var(--text-muted);font-size:.85rem;padding:2rem;">
                        Start the conversation! 👋
                    </div>
                    <?php else:
                        $prevDate = null;
                        foreach ($conversation as $msg):
                            $msgDate = date('M j, Y', strtotime($msg['created_at']));
                            $isMine  = $msg['sender_id'] == $userId;
                            if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
                            <div style="text-align:center;font-size:.7rem;color:var(--text-muted);font-weight:700;padding:.4rem 0;"><?= $msgDate ?></div>
                    <?php endif; ?>
                    <div style="display:flex;align-items:flex-end;gap:8px;width:100%;<?= $isMine ? 'flex-direction:row-reverse;' : 'flex-direction:row;' ?>">
                        <div style="flex-shrink:0;margin-bottom:2px;">
                            <?php if ($isMine): ?>
                                <?php if (!empty($meUser['profile_image'])): ?>
                                    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($meUser['profile_image']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:.65rem;"><?= strtoupper(substr($meUser['name'],0,1)) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (!empty($msg['sender_profile_image'])): ?>
                                    <img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($msg['sender_profile_image']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:.65rem;"><?= strtoupper(substr($msg['sender_name'],0,1)) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;max-width:55%;min-width:0;">
                            <?php if (!$isMine): ?>
                            <div style="font-size:.65rem;color:var(--text-muted);margin-bottom:2px;font-weight:700;"><?= sanitize($msg['sender_name']) ?></div>
                            <?php endif; ?>
                            <div class="chat-bubble <?= $isMine ? 'sent' : 'received' ?>">
                                <?= nl2br(sanitize($msg['message'])) ?>
                                <div class="chat-bubble-time"><?= date('g:ia', strtotime($msg['created_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Input -->
                <div class="chat-input-area">
                    <input type="hidden" id="receiverId" value="<?= $toId ?>">
                    <input type="text" id="messageInput" class="chat-input" placeholder="Type your message..." autocomplete="off">
                    <button id="sendBtn" class="btn-green" style="border-radius:50%;width:42px;height:42px;padding:0;justify-content:center;flex-shrink:0;">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>

                <?php else: ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--text-muted);">
                    <div>
                        <div style="font-size:3rem;margin-bottom:1rem;">💬</div>
                        <div style="font-weight:700;">Select a conversation</div>
                        <div style="font-size:.82rem;margin-top:.4rem;">Message buyers or farmers from your deliveries</div>
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

const sendBtn     = document.getElementById('sendBtn');
const msgInput    = document.getElementById('messageInput');
const receiverId  = document.getElementById('receiverId');

function sendMessage() {
    const msg = msgInput.value.trim();
    if (!msg || !receiverId) return;

    const chatMessages = document.getElementById('chatMessages');
    const now     = new Date();
    const timeStr = now.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true}).toLowerCase();

    <?php if (!empty($meUser['profile_image'])): ?>
    const avatarHtml = `<img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($meUser['profile_image']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">`;
    <?php else: ?>
    const avatarHtml = `<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:.65rem;"><?= strtoupper(substr($meUser['name'],0,1)) ?></div>`;
    <?php endif; ?>

    const bubble = document.createElement('div');
    bubble.style.cssText = 'display:flex;align-items:flex-end;gap:8px;width:100%;flex-direction:row-reverse;';
    bubble.innerHTML = `
        <div style="flex-shrink:0;margin-bottom:2px;">${avatarHtml}</div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;max-width:55%;min-width:0;">
            <div class="chat-bubble sent">
                ${msg.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}
                <div class="chat-bubble-time">${timeStr}</div>
            </div>
        </div>`;
    chatMessages.appendChild(bubble);
    msgInput.value = '';
    scrollToBottom();

    fetch('messages.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `message=${encodeURIComponent(msg)}&receiver_id=${receiverId.value}&ajax=1`
    });
}

if (sendBtn) sendBtn.addEventListener('click', sendMessage);
if (msgInput) msgInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// Poll for new messages every 3s
<?php if ($toId): ?>
setInterval(() => {
    fetch(`<?= BASE_URL ?>/messages/poll.php?to=<?= $toId ?>&after=<?= !empty($conversation) ? end($conversation)['id'] : 0 ?>`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) return;
            const chatMessages = document.getElementById('chatMessages');
            data.forEach(m => {
                <?php if (!empty($currentContact['profile_image'])): ?>
                const theirAvatar = `<img src="<?= BASE_URL ?>/assets/images/profiles/<?= htmlspecialchars($currentContact['profile_image']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">`;
                <?php else: ?>
                const theirAvatar = `<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:.65rem;"><?= $currentContact ? strtoupper(substr($currentContact['name'],0,1)) : '' ?></div>`;
                <?php endif; ?>
                const bubble = document.createElement('div');
                bubble.style.cssText = 'display:flex;align-items:flex-end;gap:8px;width:100%;flex-direction:row;';
                bubble.innerHTML = `
                    <div style="flex-shrink:0;margin-bottom:2px;">${theirAvatar}</div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;max-width:55%;min-width:0;">
                        <div class="chat-bubble received">
                            ${m.message.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/\n/g,'<br>')}
                            <div class="chat-bubble-time">${m.time}</div>
                        </div>
                    </div>`;
                chatMessages.appendChild(bubble);
            });
            scrollToBottom();
        }).catch(()=>{});
}, 3000);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>