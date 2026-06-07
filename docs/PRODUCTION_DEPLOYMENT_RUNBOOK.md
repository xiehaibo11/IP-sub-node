# Production VPN Subscription Runbook

本文档是当前线上部署的准则。不要按旧 WireGuard 单协议文档部署当前项目。

目标是让新开发者从空服务器开始部署时，清楚知道每一层流量怎么走、每个端口由谁监听、订阅为什么分成桌面端和手机端，以及遇到超时应该先查哪里。

## 1. 当前结论

当前生产架构不是单纯 WireGuard。

线上按客户端类型分发两个订阅：

- 桌面端 Clash/Mihomo/Clash Verge/Clash Mi：`/sub/client.yaml`
- 手机端 Hiddify/sing-box：`/sub/mobile.json`

线上按网络环境提供三个节点：

- `VLESS WS TLS 443`：手机移动网络优先，走标准 HTTPS 443，经 nginx 转发到本机 Xray。
- `Hysteria2 UDP 8443`：速度优先，适合 UDP 没被限制的网络。
- `VLESS TCP TLS 8443`：基础 TCP TLS 节点，作为兜底。

不要再把所有客户端都导入同一个 `/sub` 地址，也不要依赖 User-Agent 自动判断格式。Clash 和 Hiddify 要拿不同的配置格式。

## 2. 流量路径

### 2.1 订阅获取路径

桌面端：

```text
Clash/Mihomo/Clash Verge
  -> https://DOMAIN/sub/client.yaml
  -> nginx rewrite
  -> /api/vpn/clash.php?token=TOKEN
  -> MySQL vpn_clients 校验 token
  -> 输出 Clash YAML
```

手机端：

```text
Hiddify
  -> https://DOMAIN/sub/mobile.json
  -> nginx rewrite
  -> /api/vpn/hiddify.php?token=TOKEN
  -> MySQL vpn_clients 校验 token
  -> 输出 sing-box JSON
```

token 只用于订阅授权。不要把真实 token 提交到 Git。

### 2.2 代理数据路径

手机优先路径，最稳：

```text
Hiddify
  -> VLESS + WebSocket + TLS
  -> DOMAIN:443
  -> nginx TLS 入口
  -> exact WebSocket location
  -> http://127.0.0.1:10086
  -> Xray mobile-ws-in
  -> direct outbound
```

加速路径，依赖 UDP：

```text
Hiddify 或 Mihomo
  -> Hysteria2
  -> DOMAIN:8443/udp
  -> sing-box@hy2
  -> direct outbound
```

TCP 兜底路径：

```text
Clash/Mihomo/Hiddify
  -> VLESS + TCP + TLS
  -> DOMAIN:8443/tcp
  -> Xray vless inbound
  -> direct outbound
```

## 3. 端口归属

| 端口 | 协议 | 进程 | 用途 |
| --- | --- | --- | --- |
| `443/tcp` | HTTPS / WSS | nginx | 订阅、网站、VLESS WS TLS 入口 |
| `10086/tcp` | VLESS WS 明文内环 | Xray | 只监听 `127.0.0.1`，由 nginx 反代 |
| `8443/tcp` | VLESS TCP TLS | Xray | 桌面和兜底节点 |
| `8443/udp` | Hysteria2 | sing-box | 加速节点 |
| `8080/tcp` | WebSocket app | Node.js | 项目原 websocket 服务，按需 PM2 管理 |

安全组至少放行：

```text
443/tcp
8443/tcp
8443/udp
22/tcp 只给管理 IP
```

`10086/tcp` 不要对公网监听。

## 4. 必备环境变量

从 `.env.example` 复制：

```sh
cp .env.example .env
chmod 600 .env
```

必须配置：

```ini
DB_HOST=127.0.0.1
DB_USER=...
DB_PASSWORD=...
DB_NAME=clients

SUBSCRIPTION_NODE_UUID=...
SUBSCRIPTION_NODE_HOST=DOMAIN
SUBSCRIPTION_NODE_PORT=8443
SUBSCRIPTION_NODE_SNI=DOMAIN
SUBSCRIPTION_NODE_NAME=美国-client1

HYSTERIA2_ENABLED=true
HYSTERIA2_NAME=美国-HY2
HYSTERIA2_PORT=8443
HYSTERIA2_PASSWORD=...
HYSTERIA2_OBFS_PASSWORD=...
HYSTERIA2_UP_MBPS=100
HYSTERIA2_DOWN_MBPS=500

MOBILE_WS_ENABLED=true
MOBILE_WS_NAME=美国-443
MOBILE_WS_PORT=443
MOBILE_WS_LOCAL_PORT=10086
MOBILE_WS_PATH=/api/your-private-ws-path
MOBILE_WS_HOST=DOMAIN
MOBILE_WS_SNI=DOMAIN
```

`MOBILE_WS_PATH` 必须同时出现在三处：

- `.env` 的 `MOBILE_WS_PATH`
- nginx 的 exact `location = /api/your-private-ws-path`
- Xray WebSocket inbound 的 `wsSettings.path`

三处任何一个不一致，手机 443 节点会超时或 404。

## 5. 数据库初始化

新服务器创建数据库后导入结构：

```sh
mysql -u "$DB_USER" -p -e "CREATE DATABASE IF NOT EXISTS clients CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u "$DB_USER" -p clients < database/schema.sql
```

必须有 `vpn_clients` 可用记录，否则订阅接口会返回 `404 subscription not found`。

最少需要这些字段有效：

- `client_name`
- `username`
- `vpn_address`
- `private_key`
- `public_key`
- `preshared_key`
- `server_public_key`
- `endpoint_host`
- `endpoint_port`
- `token`
- `enabled = 1`

当前 VLESS/HY2 订阅主要使用 token 校验和客户端命名。旧 WireGuard 字段仍在表里，是兼容旧逻辑，不代表当前生产流量走 WireGuard。

生产行数据不要放进 Git，因为里面有 VPN key 和订阅 token。

## 6. nginx 配置要点

订阅短链：

```nginx
location = /sub/client.yaml {
    rewrite ^ /api/vpn/clash.php?token=CHANGE_ME_64_HEX_TOKEN last;
}

location = /sub/mobile.json {
    rewrite ^ /api/vpn/hiddify.php?token=CHANGE_ME_64_HEX_TOKEN last;
}

location ~ "^/sub/([a-f0-9]{64})/client\.yaml$" {
    rewrite "^/sub/([a-f0-9]{64})/client\.yaml$" /api/vpn/clash.php?token=$1 last;
}

location ~ "^/sub/([a-f0-9]{64})/mobile\.json$" {
    rewrite "^/sub/([a-f0-9]{64})/mobile\.json$" /api/vpn/hiddify.php?token=$1 last;
}
```

VPN PHP endpoint：

```nginx
location ~ ^/api/vpn/(auto|clash|hiddify|sub|info)\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

手机 443 WebSocket fallback：

```nginx
location = /api/your-private-ws-path {
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_pass http://127.0.0.1:10086;
}
```

注意：

- WebSocket location 要用实际 `MOBILE_WS_PATH`。
- 不能被敏感文件拦截规则误伤。
- `proxy_set_header Upgrade` 和 `Connection "upgrade"` 必须存在。
- 如果网站 root 不是仓库目录，`SCRIPT_FILENAME` 必须能找到 `api/vpn/*.php`。

验证：

```sh
nginx -t
systemctl reload nginx
```

HTTPS 站点安全头必须包含 HSTS，避免用户先访问 HTTP 或浏览器历史缓存导致地址栏显示“不安全”：

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

确认：

```sh
curl -I https://DOMAIN/sub/client.yaml | rg -i 'strict-transport-security|http/'
```

## 7. Xray 配置要点

Xray 至少要有两个 inbound：

- `8443/tcp`：VLESS TCP TLS。
- `127.0.0.1:10086`：VLESS WebSocket，给 nginx 443 fallback 使用。

VLESS 用户 UUID 必须等于 `.env` 的 `SUBSCRIPTION_NODE_UUID`。

重要坑点：不要随便加 `xtls-rprx-vision` flow。如果客户端订阅没有输出 `flow`，服务端却要求 Vision flow，Xray 会拒绝连接。当前配置使用普通 VLESS TLS，不设置 flow。

WebSocket inbound 只监听本机：

```json
{
  "tag": "mobile-ws-in",
  "listen": "127.0.0.1",
  "port": 10086,
  "protocol": "vless",
  "settings": {
    "clients": [
      {
        "id": "SUBSCRIPTION_NODE_UUID",
        "email": "mobile"
      }
    ],
    "decryption": "none"
  },
  "streamSettings": {
    "network": "ws",
    "security": "none",
    "wsSettings": {
      "path": "/api/your-private-ws-path"
    }
  }
}
```

验证：

```sh
xray run -test -c /usr/local/etc/xray/config.json
systemctl restart xray
systemctl is-active xray
```

日志：

```sh
journalctl -u xray -n 100 --no-pager
```

## 8. sing-box Hysteria2 配置要点

HY2 监听 `8443/udp`，密码和 obfs 密码来自 `.env`：

```json
{
  "inbounds": [
    {
      "type": "hysteria2",
      "tag": "hy2-in",
      "listen": "::",
      "listen_port": 8443,
      "users": [
        {
          "password": "HYSTERIA2_PASSWORD"
        }
      ],
      "obfs": {
        "type": "salamander",
        "password": "HYSTERIA2_OBFS_PASSWORD"
      },
      "tls": {
        "enabled": true,
        "server_name": "DOMAIN",
        "certificate_path": "/etc/letsencrypt/live/DOMAIN/fullchain.pem",
        "key_path": "/etc/letsencrypt/live/DOMAIN/privkey.pem",
        "alpn": ["h3"]
      }
    }
  ],
  "outbounds": [
    {
      "type": "direct",
      "tag": "direct"
    }
  ]
}
```

验证：

```sh
sing-box check -c /etc/sing-box/hy2.json
systemctl restart sing-box@hy2
systemctl is-active sing-box@hy2
```

如果手机流量下 HY2 超时，不代表 Hiddify 不支持 HY2。更常见原因是运营商限制 UDP 或该网络对 UDP 8443 不稳定。此时让手机优先使用 `美国-443`。

## 9. 内核和系统优化

建议启用 BBR、fq、TCP Fast Open 和更大的 buffer：

```conf
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_mtu_probing = 1
net.ipv4.tcp_slow_start_after_idle = 0
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.tcp_fin_timeout = 15
net.ipv4.tcp_keepalive_time = 120
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 3
```

写入 `/etc/sysctl.d/99-xray-performance.conf` 后执行：

```sh
sysctl --system
sysctl net.ipv4.tcp_congestion_control
tc qdisc show dev "$(ip route show default | awk '{print $5; exit}')"
```

如果服务器没有可用 IPv6 出口，订阅里要关闭 IPv6 或强制 IPv4。否则客户端可能出现 DNS 能解析、HTTP/TLS 握手超时。

## 10. 客户端导入规则

桌面 Clash/Mihomo/Clash Verge/Clash Mi：

```text
https://DOMAIN/sub/client.yaml
```

手机 Hiddify：

```text
https://DOMAIN/sub/mobile.json
```

手机端排障时必须这样做：

1. 删除旧 profile，不要只点刷新。
2. 重新导入 `/sub/mobile.json`。
3. 手动选择 `美国-443`。
4. 关闭 Hiddify 里的 WARP、链式代理或前置代理。
5. 移动流量下先测 `美国-443`，再测 HY2。

如果 Hiddify 页面显示 `WARP -> 美国-client1`，说明它不是直连节点，而是先走 WARP 再连节点。这种链式路径容易在移动网络握手超时，不能作为节点本身是否正常的判断依据。

## 11. fake-ip 和规则

Clash/Mihomo 订阅使用 fake-ip：

```yaml
dns:
  enable: true
  ipv6: false
  enhanced-mode: fake-ip
  fake-ip-range: 198.18.0.1/16
```

fake-ip 用来减少 DNS 污染影响，并提升规则路由性能。

必须保留常见过滤项：

- `*.lan`
- `*.local`
- `localhost`
- Apple captive portal 和 time 域名
- NTP
- Windows connectivity check
- Android connectivity check
- Firefox portal check

规则最后必须有兜底：

```yaml
MATCH,PROXY
```

内网和本机地址必须直连，避免把局域网、系统探测、NTP 都代理出去。

## 12. 完整验证清单

服务状态：

```sh
systemctl is-active nginx xray sing-box@hy2
```

端口监听：

```sh
ss -lntup | rg ':(443|8443|10086)\b'
```

期望结果：

- nginx 监听 `443/tcp`
- Xray 监听 `8443/tcp`
- Xray 监听 `127.0.0.1:10086/tcp`
- sing-box 监听 `8443/udp`

订阅接口：

```sh
curl -fsSL https://DOMAIN/sub/client.yaml | sed -n '1,80p'
curl -fsSL https://DOMAIN/sub/mobile.json -o /tmp/mobile-sub.json
sing-box check -c /tmp/mobile-sub.json
```

日志观察：

```sh
tail -n 100 /var/log/nginx/access.log
journalctl -u xray --since '10 minutes ago' --no-pager
journalctl -u sing-box@hy2 --since '10 minutes ago' --no-pager
```

WebSocket 443 正常时，nginx access log 能看到该 path 返回 `101`。

## 13. 常见错误和正确处理

| 现象 | 常见原因 | 正确处理 |
| --- | --- | --- |
| Clash 导入订阅失败，`failed to fetch remote profile` | `/sub` 自动判断格式、nginx rewrite、PHP token 或 TLS 问题 | 固定使用 `/sub/client.yaml`，先 `curl` 看 HTTP 状态 |
| Clash 里节点显示 Timeout | 服务端协议和订阅不匹配，或 8443/tcp 未通 | 查 `ss`、安全组、Xray 日志 |
| Hiddify 手机流量超时 | 还在用旧 profile、WARP 链式代理、移动网络限制 UDP 或非 443 | 删除旧 profile，重导 `/sub/mobile.json`，选 `美国-443`，关闭 WARP |
| HY2 在 Wi-Fi 可用，手机流量不可用 | 运营商 UDP 限制 | 手机默认走 VLESS WS TLS 443，HY2 只作加速可选项 |
| Xray 日志提示 flow 不匹配 | 服务端设置了 Vision flow，客户端没带 flow | 服务端和订阅都不要设置 Vision flow，或两边同时设置 |
| 443 WS 连接 404 | nginx path、Xray path、`.env MOBILE_WS_PATH` 不一致 | 三处 path 完全一致 |
| 443 WS 连接 502 | nginx 代理到 `127.0.0.1:10086`，但 Xray 没监听 | 查 Xray service 和 inbound |
| Hiddify 能导入但没有节点 | sing-box JSON 结构不兼容或输出被错误缓存 | `curl` 下载后用 `sing-box check` 校验 |
| 订阅返回 404 | 数据库没有 enabled token 记录或 token 过期 | 查 `vpn_clients` 记录 |
| 速度不稳定 | IPv6 黑洞、DNS 污染、UDP 不稳、链式代理 | 关闭 IPv6、fake-ip、443 fallback、关闭 WARP |

## 14. 不要再重复的旧路

不要做这些事：

- 不要把 WireGuard 当作当前生产主协议。
- 不要让桌面端和手机端共用一个订阅格式。
- 不要只看 Hiddify 支持协议列表就认为导入格式一定正确。
- 不要让手机端默认走 HY2 或 8443，再去判断节点是否可用。
- 不要在 Hiddify 开着 WARP 链式代理时测节点连通性。
- 不要把 Xray Vision flow 加到服务端但不输出给客户端。
- 不要把真实 `.env`、UUID、HY2 密码、WebSocket 私有 path、GitHub token、订阅 token、数据库行数据提交到 Git。
- 不要只改 PHP 代码就以为部署完成；nginx、Xray、sing-box、系统端口必须一起验证。

## 15. 新服务器最短部署顺序

1. 安装系统依赖：nginx、PHP-FPM、MySQL/MariaDB、Node.js、PM2、Xray、sing-box。
2. Clone 仓库到 `/var/www/IP-sub-node`。
3. `cp .env.example .env`，填好数据库、VLESS、HY2、Mobile WS 配置。
4. 导入 `database/schema.sql`。
5. 插入或迁移 `vpn_clients` 的授权记录。
6. 配置 nginx：订阅 rewrite、PHP-FPM、WebSocket fallback。
7. 配置 Xray：8443/tcp VLESS TLS 和 127.0.0.1:10086 VLESS WS。
8. 配置 sing-box：8443/udp HY2。
9. 应用 sysctl 优化。
10. 重启并校验 nginx、xray、sing-box。
11. 用 curl 校验两个订阅。
12. 用 Clash 导入 `/sub/client.yaml`。
13. 用 Hiddify 删除旧 profile 后导入 `/sub/mobile.json`，优先选 `美国-443`。

## 16. 交接时必须说明

交接给其他开发者时，要明确告诉对方：

- 这是多协议订阅分发系统，不是单 WireGuard 节点。
- 桌面和手机订阅 URL 不一样。
- Hiddify 手机端默认优先 443 fallback。
- HY2 是加速项，不是移动网络唯一入口。
- 数据库结构可以 Git 管理，生产数据不能 Git 管理。
- 任何连接超时都必须按“订阅接口、客户端选择、nginx、Xray、sing-box、端口、安全组、日志”的顺序排查。
