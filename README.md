# Agnes AI Tools — WordPress 插件

把 [Agnes AI](https://apihub.agnes-ai.com/v1)（OpenAI 兼容）的对话 / 画图 / 文案能力，以**独立新页面**的形式挂到 WordPress 站点上。

**当前版本：0.1.0**

---

## 一、功能

- **三个独立页面**（不挂在现有页面上，各自新建）：
  - `/ai-chat/` —— AI 对话（`[agnes_chat]`）
  - `/ai-image/` —— AI 画图（`[agnes_image]`）
  - `/ai-copy/` —— AI 写文案（`[agnes_copy]`）
- **设置页**：只存 API Key 到数据库（不写进代码 / 不进仓库）
- **连通性自检**：设置页「测试连接」按钮，发一次最小请求验证能连到 Agnes
- **IP 限流**：每 10 分钟 12 次，防滥用
- **REST 路由**：`/agnes-ai/v1/chat`、`/image`、`/copy`，带 nonce + 限流

> Agnes 文本模型 `agnes-2.5-flash` 默认开启 thinking 模式，会占用 `max_tokens`；
> 自检用 256 token 即可确认连通（HTTP 200 且返回 `model` 字段即算通）。

---

## 二、安装

1. `wp-admin` → **插件 → 安装插件 → 上传插件** → 选 `agnes-ai-tools.zip`
2. 启用后到 **设置 → Agnes AI Tools** 填入 API Key 并点「测试连接」
3. 三个页面（`/ai-chat/`、`/ai-image/`、`/ai-copy/`）由短代码渲染，按需放到对应页面

---

## 三、GitHub 自动更新（自 v0.1.0 起）

内置 [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)（已打包在 `plugin-update-checker/`），
指向公开仓库 **`sunfei0318-code/agnes-ai-tools`** 的 `main` 分支。

**以后发新版**：改代码 → 改 `agnes-ai-tools.php` 顶部 `Version:` → 推到 GitHub 并打 `v` 开头的 tag（如 `v0.1.1`）：

```bash
git add -A
git commit -m "release: v0.1.1"
git tag v0.1.1
git push origin main --tags
```

WordPress 后台「插件」页会在几分钟内检测到新版本并提供「更新」。

---

## 四、安全

- API Key **只存在 WP 数据库**，不进任何文件 / 仓库。
- 仓库不含任何密钥；私有仓库才需在 `agnes-ai-tools.php` 末尾填 GitHub Token。
