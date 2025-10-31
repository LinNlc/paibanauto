# 排班助手脚手架

## 技术栈
- PHP 8.x（兼容 PHP-FPM）
- SQLite3
- 原生 HTML / JavaScript
- TailwindCSS CDN
- Chart.js CDN

## 目录结构
```
/api/               # PHP API 入口与公共库
/bin/               # 安装与维护脚本
/config/            # 应用配置
/docs/              # 文档
/public/            # 静态页面与样式
/schema/            # 数据库初始化脚本
```

## 初始化步骤
1. 确认环境已经安装 `sqlite3`、`php`、`php-fpm`。
2. 在项目根目录执行安装脚本：
   ```bash
   bin/install.sh
   ```
   该脚本会创建 `data/` 目录并初始化 `data/schedule.db`。
3. 将 OpenResty / Nginx 静态站点根目录指向 `public/`。
4. 配置 PHP-FPM 解析 `/api/*.php` 请求（FastCGI 或其他方式）。
5. 访问 `/public/index.html` 将自动跳转到登录页。

## 默认账号
- 用户名：`admin`
- 密码：`admin`

> 请在后续实现登录逻辑后尽快修改默认密码。

## 部署指南
本项目推荐在启用 HTTPS 的 OpenResty / Nginx + PHP-FPM 环境中运行，可直接参考 `config/nginx.conf.sample`。以下说明结合 1Panel 面板的常见配置步骤。

### 1Panel 下部署步骤
1. **准备站点目录**：将仓库解压到服务器，例如 `/opt/paiban`，确保 `public/`、`api/` 等目录完整。
2. **执行初始化脚本**：登录服务器执行 `bin/install.sh`，生成 `data/schedule.db` 并写入默认团队与管理员账号。
3. **在 1Panel 创建站点**：
   - 新建站点，类型选择 *纯静态*（或自定义），根目录指向仓库的 `public/`。
   - 启用 HTTPS，上传或申请证书，填入证书与密钥路径。
4. **PHP-FPM 反向代理**：在站点高级配置中加入 FastCGI 设置，使 `/api/` 下的 PHP 通过 PHP-FPM 解析。可直接粘贴 `config/nginx.conf.sample` 内相关 `location` 段落到 1Panel 的“自定义配置”。
5. **SSE 配置**：确保 `location ~ ^/api/sse.php` 块包含 `fastcgi_buffering off;`、`add_header X-Accel-Buffering no;`，并关闭 gzip，以免中断实时推送。
6. **应用配置并重载 Nginx**：保存 1Panel 中的站点配置，执行重载。
7. **验证部署**：
   - 浏览器访问 `https://<域名>/login.html`，确保能显示登录页面。
   - 登录后打开排班页 `/schedule.html`，在两个浏览器标签页中测试同一团队的单元格编辑，确认实时同步。

### 常见错误排查
- **500 Internal Server Error**：检查 PHP-FPM 日志是否缺少 SQLite 扩展、文件权限不足或 FastCGI 参数错误。确保 `SCRIPT_FILENAME` 指向实际 PHP 文件。
- **404 Not Found**：确认站点根目录指向 `public/`，访问 API 时使用 `/api/*.php` 完整路径。
- **405 Method Not Allowed**：通常由于 SSE 或 API 请求被静态规则拦截。在 1Panel 中确认 `/api/` 使用 FastCGI，`/api/sse.php` 未被重写为静态资源。
- **403 Forbidden**：
  - 未登录或权限不足时，接口会返回 403。检查 Cookie 中的 Session 是否正确。
  - Nginx 层若返回 403，确认站点用户对 `data/`、`api/` 等目录具有读取权限。
- **SSE 无法保持连接**：
  - 确保 `proxy_buffering` 或 `fastcgi_buffering` 关闭，并设置 `add_header X-Accel-Buffering no;`。
  - 若开启了全局 gzip，请在 SSE 路径显式 `gzip off;`。
  - 检查浏览器控制台是否有跨域或证书错误。

### 安全建议
- 启用 HSTS、X-Frame-Options、X-Content-Type-Options、Referrer-Policy 等响应头以提升安全性。
- 默认管理员密码为演示用途，部署后立即重置，并在后台禁用不必要的账号。
- 定期备份 `data/schedule.db` 并将备份存放在安全位置。

