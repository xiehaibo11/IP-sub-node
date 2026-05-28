# MM 独立节点部署

这个目录是从 `/root/MM` 的 git 历史提交 `0372eb2` 单独拆出的 WebSocket/节点服务。原仓库里没有搜到 `vpn`、`openvpn`、`wireguard`、`xray` 等 VPN 部署脚本；这里拆出来的是历史里可独立运行的 Node WebSocket 控制节点。

## 文件范围

- `api/ws/websocket-server.js`：节点主进程，同时监听 WebSocket 和一个 HTTP 端口。
- `api/ws/keepalive-policy.js`：设备在线状态保活策略。
- `api/ws/package.json`：Node 依赖。
- `private/app_config.php`、`private/cli_get_email.php`：设备归属转移时查询数据库的最小 PHP 依赖。
- `.env.example`：节点运行所需配置模板。

## 快速启动

```bash
cd /root/MM-vpn-node
cp .env.example .env
```

编辑 `/root/MM-vpn-node/.env`，至少确认这些值：

```env
DB_HOST=127.0.0.1
DB_USER=你的数据库用户
DB_PASSWORD=你的数据库密码
DB_NAME=你的数据库名
SECRET_KEY=和主站一致的32字节密钥
SECRET_IV=和主站一致的16字节IV
WS_ADMIN_TOKEN=强随机管理token
WS_DEVICE_AUTH_TOKEN=强随机设备token
WS_ALLOWED_ORIGINS=https://你的域名
WS_HOST=127.0.0.1
WS_PORT=18080
HTTP_HOST=127.0.0.1
HTTP_PORT=13000
APK_STUB_PATH=/www/wwwroot/你的域名/private/apkstub/apkstub.zip
```

安装依赖并启动：

```bash
cd /root/MM-vpn-node/api/ws
npm install
WS_PORT=18080 HTTP_PORT=13000 node websocket-server.js
```

## PM2 启动

```bash
npm install -g pm2
pm2 start /root/MM-vpn-node/ecosystem.config.js
pm2 save
pm2 startup
```

## Nginx 反向代理

在主站的 `server {}` 中把 `/api/ws/` 反代到独立节点端口：

```nginx
location /api/ws/ {
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_pass http://127.0.0.1:18080;
}
```

如果节点部署在另一台机器，把 `proxy_pass` 改成内网地址或公网地址，并在防火墙只放行主站到节点的访问。
