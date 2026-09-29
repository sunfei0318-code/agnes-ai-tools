<?php
/**
 * Plugin Name: Agnes AI Tools
 * Description: 服务端代理 Agnes AI，提供 AI 聊天 / 文生图 / 文案生成。API Key 仅存后台数据库，前端只收结果，绝不暴露密钥。
 * Version: 0.1.0
 * Author: sun-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGNES_AI_VERSION', '0.1.0');

// ---------------------------------------------------------------------------
// 设置页：API Key 仅保存在站点 options（数据库），不下发前端
// ---------------------------------------------------------------------------
add_action('admin_menu', function () {
    add_options_page('Agnes AI Tools', 'Agnes AI Tools', 'manage_options', 'agnes-ai-tools', 'agnes_ai_options_page');
});

add_action('admin_init', function () {
    foreach (['agnes_ai_key', 'agnes_ai_base', 'agnes_ai_model', 'agnes_ai_image_model', 'agnes_ai_thinking'] as $k) {
        register_setting('agnes_ai', $k);
    }
});

function agnes_ai_options_page() {
    ?>
    <div class="wrap">
        <h1>Agnes AI Tools</h1>
        <p>API Key 只保存在本站点数据库，<strong>永远不会下发到浏览器/前端代码</strong>。所有对 Agnes 的调用都由服务器代发。</p>
        <form method="post" action="options.php">
            <?php settings_fields('agnes_ai'); ?>
            <table class="form-table">
                <tr><th>API Key</th><td><input type="password" class="regular-text" name="agnes_ai_key" value="<?php echo esc_attr(get_option('agnes_ai_key')); ?>"></td></tr>
                <tr><th>Base URL</th><td><input type="text" class="regular-text" name="agnes_ai_base" value="<?php echo esc_attr(get_option('agnes_ai_base', 'https://apihub.agnes-ai.com/v1')); ?>"></td></tr>
                <tr><th>文本模型</th><td><input type="text" class="regular-text" name="agnes_ai_model" value="<?php echo esc_attr(get_option('agnes_ai_model', 'agnes-2.5-flash')); ?>"><br><small>Agnes 迭代时只改这里即可</small></td></tr>
                <tr><th>图像模型</th><td><input type="text" class="regular-text" name="agnes_ai_image_model" value="<?php echo esc_attr(get_option('agnes_ai_image_model', 'agnes-image-2.1-flash')); ?>"></td></tr>
                <tr><th>思考模式</th><td><input type="checkbox" name="agnes_ai_thinking" value="1" <?php checked('1', get_option('agnes_ai_thinking', '1')); ?>> 开启（若新模型推理吃掉正文，取消勾选）</td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2>连通性自检</h2>
        <p>点此测试 Wasmer 上的 PHP 能否向外访问 Agnes 网关——这决定插件能否直接用。失败则说明 Wasmer 封出网，需要改用「独立应用 + iframe」方案。</p>
        <button type="button" class="button" id="agnes-test-btn">测试连接</button>
        <pre id="agnes-test-result" style="margin-top:10px;white-space:pre-wrap;"></pre>
    </div>
    <script>
    document.getElementById('agnes-test-btn').addEventListener('click', function () {
        var b = this, r = document.getElementById('agnes-test-result');
        r.textContent = '测试中…'; b.disabled = true;
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=agnes_ai_test&nonce=<?php echo wp_create_nonce('agnes-ai-admin'); ?>'
        }).then(function (x) { return x.json(); }).then(function (d) {
            r.textContent = JSON.stringify(d, null, 2); b.disabled = false;
        }).catch(function (e) { r.textContent = '请求失败: ' + e; b.disabled = false; });
    });
    </script>
    <?php
}

// 连通性自检（仅管理员）
add_action('wp_ajax_agnes_ai_test', function () {
    check_ajax_referer('agnes-ai-admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json(['ok' => false, 'error' => '权限不足']);
    }
    $c = agnes_ai_config();
    // 连通性测试的目的只是证明「服务器能连上 Agnes」。
    // 只要 HTTP 200 且返回里带 model 字段，就算连通——正文空不空是另一回事
    // （思考模式下 max_tokens 太小时，正文会被 reasoning 吃光，但那恰好证明接口是通的）。
    $r = agnes_ai_chat_raw('ping', 256);
    if (empty($r['ok']) && isset($r['raw']['model'])) {
        $r = [
            'ok'      => true,
            'note'    => '连通正常（HTTP 200，已收到 Agnes 响应）。若 content 为空，是思考模式吃掉了 token，不影响使用。',
            'model'   => $r['raw']['model'],
            'content' => $r['raw']['choices'][0]['message']['content'] ?? '',
            'usage'   => $r['raw']['usage'] ?? null,
        ];
    }
    $r['model_configured'] = $c['model'];
    wp_send_json($r);
});

// ---------------------------------------------------------------------------
// 核心：服务端调用 Agnes（OpenAI 兼容）
// ---------------------------------------------------------------------------
function agnes_ai_config() {
    return [
        'key'      => get_option('agnes_ai_key', ''),
        'base'     => rtrim(get_option('agnes_ai_base', 'https://apihub.agnes-ai.com/v1'), '/'),
        'model'    => get_option('agnes_ai_model', 'agnes-2.5-flash'),
        'img'      => get_option('agnes_ai_image_model', 'agnes-image-2.1-flash'),
        'thinking' => get_option('agnes_ai_thinking', '1') === '1',
    ];
}

function agnes_ai_chat_raw($prompt, $max_tokens = 800, $system = '') {
    $c = agnes_ai_config();
    if (!$c['key']) {
        return ['ok' => false, 'error' => 'API key 未配置（请在设置页填写）'];
    }
    $messages = [];
    if ($system) {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];
    $body = [
        'model'       => $c['model'],
        'messages'    => $messages,
        'max_tokens'  => (int) $max_tokens,
        'temperature' => 0.7,
    ];
    if ($c['thinking']) {
        $body['chat_template_kwargs'] = ['enable_thinking' => true];
    }
    $resp = wp_remote_post($c['base'] . '/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $c['key'],
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => 120,
    ]);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'error' => $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($data['choices'][0]['message']['content'])) {
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'raw' => $data];
    }
    $content = $data['choices'][0]['message']['content'];
    $content = preg_replace('/^```[A-Za-z0-9]*\n/', '', $content);
    $content = preg_replace('/\n```\s*$/', '', $content);
    return ['ok' => true, 'content' => trim($content)];
}

function agnes_ai_image_raw($prompt, $size = '1024x1024') {
    $c = agnes_ai_config();
    if (!$c['key']) {
        return ['ok' => false, 'error' => 'API key 未配置'];
    }
    $body = ['model' => $c['img'], 'prompt' => $prompt, 'size' => $size, 'n' => 1];
    $resp = wp_remote_post($c['base'] . '/images/generations', [
        'headers' => ['Authorization' => 'Bearer ' . $c['key'], 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 120,
    ]);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'error' => $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($data['data'][0])) {
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'raw' => $data];
    }
    $item = $data['data'][0];
    if (!empty($item['b64_json'])) {
        return ['ok' => true, 'image' => 'data:image/png;base64,' . $item['b64_json']];
    }
    if (!empty($item['url'])) {
        return ['ok' => true, 'image' => $item['url']];
    }
    return ['ok' => false, 'error' => '返回中无图片字段', 'raw' => $data];
}

// ---------------------------------------------------------------------------
// 限流：按 IP，10 分钟内最多 12 次（防 Key 被刷）
// ---------------------------------------------------------------------------
function agnes_ai_ratelimit($prefix) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $k  = 'agnes_rl_' . $prefix . '_' . md5($ip);
    $n  = get_transient($k);
    if ($n === false) {
        set_transient($k, 1, 600);
        return true;
    }
    if ((int) $n >= 12) {
        return false;
    }
    set_transient($k, (int) $n + 1, 600);
    return true;
}

// ---------------------------------------------------------------------------
// 公开 REST 端点（前端调用，必须带 nonce）
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('agnes-ai/v1', '/chat', [
        'methods'             => 'POST',
        'permission_callback' => 'agnes_ai_nonce_ok',
        'callback'            => function ($req) {
            if (!agnes_ai_ratelimit('chat')) {
                return new WP_Error('rate', '请求过于频繁，请稍后再试', ['status' => 429]);
            }
            $prompt = trim((string) $req->get_param('prompt'));
            if (strlen($prompt) < 1) {
                return new WP_Error('empty', '请输入内容', ['status' => 400]);
            }
            return agnes_ai_chat_raw($prompt, 800);
        },
    ]);
    register_rest_route('agnes-ai/v1', '/copy', [
        'methods'             => 'POST',
        'permission_callback' => 'agnes_ai_nonce_ok',
        'callback'            => function ($req) {
            if (!agnes_ai_ratelimit('copy')) {
                return new WP_Error('rate', '请求过于频繁', ['status' => 429]);
            }
            $topic = trim((string) $req->get_param('topic'));
            $tone  = trim((string) $req->get_param('tone'));
            if (!$topic) {
                return new WP_Error('empty', '请填写主题', ['status' => 400]);
            }
            $system = '你是一个专业的文案写手，根据给定的主题和语气撰写原创内容，直接输出正文，不要解释你的工作过程。';
            $prompt = '主题：' . $topic . "\n语气：" . ($tone ?: '自然专业') . "\n请写一篇 300-600 字的内容。";
            return agnes_ai_chat_raw($prompt, 1200, $system);
        },
    ]);
    register_rest_route('agnes-ai/v1', '/image', [
        'methods'             => 'POST',
        'permission_callback' => 'agnes_ai_nonce_ok',
        'callback'            => function ($req) {
            if (!agnes_ai_ratelimit('image')) {
                return new WP_Error('rate', '请求过于频繁', ['status' => 429]);
            }
            $prompt = trim((string) $req->get_param('prompt'));
            $size   = trim((string) $req->get_param('size'));
            if (!$prompt) {
                return new WP_Error('empty', '请输入提示词', ['status' => 400]);
            }
            return agnes_ai_image_raw($prompt, $size ?: '1024x1024');
        },
    ]);
});

function agnes_ai_nonce_ok($req) {
    $n = $req->get_header('X-WP-Nonce');
    if (!$n) {
        $n = $req->get_param('nonce');
    }
    return ($n && wp_verify_nonce($n, 'agnes-ai-public'));
}

// ---------------------------------------------------------------------------
// 前端短代码
// ---------------------------------------------------------------------------
add_shortcode('agnes_chat', function () {
    $nonce = wp_create_nonce('agnes-ai-public');
    $rest  = esc_url_raw(rest_url('agnes-ai/v1/chat'));
    ob_start();
    ?>
    <div class="agnes-box" style="max-width:640px;margin:16px 0;font-family:system-ui,sans-serif">
        <textarea id="agnes-chat-input" rows="3" style="width:100%;padding:8px" placeholder="问点什么…"></textarea>
        <button id="agnes-chat-send" class="button button-primary" style="margin-top:8px">发送</button>
        <div id="agnes-chat-out" style="margin-top:10px;white-space:pre-wrap;min-height:24px"></div>
    </div>
    <script>
    (function () {
        var NONCE = '<?php echo $nonce; ?>', REST = '<?php echo $rest; ?>';
        document.getElementById('agnes-chat-send').addEventListener('click', function () {
            var v = document.getElementById('agnes-chat-input').value;
            var out = document.getElementById('agnes-chat-out');
            if (!v.trim()) { out.textContent = '请输入内容'; return; }
            out.textContent = '生成中…';
            fetch(REST, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify({ prompt: v }) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    out.textContent = d.ok ? d.content : ('错误：' + (d.error || '未知'));
                }).catch(function () { out.textContent = '请求失败'; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

add_shortcode('agnes_image', function () {
    $nonce = wp_create_nonce('agnes-ai-public');
    $rest  = esc_url_raw(rest_url('agnes-ai/v1/image'));
    ob_start();
    ?>
    <div class="agnes-box" style="max-width:640px;margin:16px 0;font-family:system-ui,sans-serif">
        <input id="agnes-img-input" type="text" style="width:100%;padding:8px" placeholder="描述你想生成的画面…">
        <button id="agnes-img-send" class="button button-primary" style="margin-top:8px">生成图片</button>
        <div id="agnes-img-out" style="margin-top:10px"></div>
    </div>
    <script>
    (function () {
        var NONCE = '<?php echo $nonce; ?>', REST = '<?php echo $rest; ?>';
        document.getElementById('agnes-img-send').addEventListener('click', function () {
            var v = document.getElementById('agnes-img-input').value;
            var out = document.getElementById('agnes-img-out');
            if (!v.trim()) { out.textContent = '请输入提示词'; return; }
            out.textContent = '生成中（约 10 秒）…';
            fetch(REST, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify({ prompt: v }) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (d.ok) { out.innerHTML = '<img src="' + d.image + '" style="max-width:100%;border-radius:8px">'; }
                    else { out.textContent = '错误：' + (d.error || '未知'); }
                }).catch(function () { out.textContent = '请求失败'; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

add_shortcode('agnes_copy', function () {
    $nonce = wp_create_nonce('agnes-ai-public');
    $rest  = esc_url_raw(rest_url('agnes-ai/v1/copy'));
    ob_start();
    ?>
    <div class="agnes-box" style="max-width:640px;margin:16px 0;font-family:system-ui,sans-serif">
        <input id="agnes-copy-topic" type="text" style="width:100%;padding:8px" placeholder="主题，例如：夏季咖啡新品推广">
        <input id="agnes-copy-tone" type="text" style="width:100%;padding:8px;margin-top:6px" placeholder="语气（可选），例如：轻松活泼">
        <button id="agnes-copy-send" class="button button-primary" style="margin-top:8px">生成文案</button>
        <div id="agnes-copy-out" style="margin-top:10px;white-space:pre-wrap;min-height:24px"></div>
    </div>
    <script>
    (function () {
        var NONCE = '<?php echo $nonce; ?>', REST = '<?php echo $rest; ?>';
        document.getElementById('agnes-copy-send').addEventListener('click', function () {
            var topic = document.getElementById('agnes-copy-topic').value;
            var tone = document.getElementById('agnes-copy-tone').value;
            var out = document.getElementById('agnes-copy-out');
            if (!topic.trim()) { out.textContent = '请填写主题'; return; }
            out.textContent = '生成中…';
            fetch(REST, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify({ topic: topic, tone: tone }) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    out.textContent = d.ok ? d.content : ('错误：' + (d.error || '未知'));
                }).catch(function () { out.textContent = '请求失败'; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// ---------------------------------------------------------------------------
// 自动更新：从 GitHub 仓库拉新版本，无需手动重传插件
// 仓库需公开（推荐）；若用私有仓库，取消最底部注释并填入 GitHub Token。
// 工作流程：把新版本推到 GitHub 并打 tag（如 v0.1.1），WordPress 会自动更新。
// ---------------------------------------------------------------------------
if (file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $agnes_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/sunfei0318-code/agnes-ai-tools/',
            __FILE__,
            'agnes-ai-tools'
        );
        $agnes_updater->setBranch('main');
        // 私有仓库时取消下一行注释并填入 GitHub Token：
        // $agnes_updater->getVcsApi()->setAuthentication('ghp_你的GitHubToken');
    }
}
