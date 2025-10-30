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
