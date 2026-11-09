# VPN 节点订阅

本项目通过一个带认证的 HTTPS 订阅地址分发 Clash/Mihomo YAML 和 Hiddify/sing-box JSON，实际代理流量由 nginx、Xray 和 sing-box 承载。部署与排障见 [Production VPN Subscription Runbook](docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md)。

## 统一订阅与认证

桌面和手机均导入 `https://DOMAIN/sub/<64位小写十六进制token>`。服务端根据客户端 User-Agent 和 Accept 返回对应格式；无法识别时默认返回 Clash YAML，也可用 `?format=clash` 或 `?format=singbox` 指定格式。生产配置启用两个可选协议后，同一个 token 在两种格式中都包含全部三个节点。

`/sub`、`/sub/client.yaml`、`/sub/mobile.json` 和 `/api/vpn/info.php` 同样需要认证，可传 `Authorization: Bearer <token>` 或 `?token=<token>`。旧 `/sub/<token>/client.yaml`、`mobile.json` 只有 token 匹配时才可访问，不再是免认证别名。缺失或错误凭据返回 HTTP 401；未配置认证哈希返回 503。

Token 由 32 个随机字节编码为 64 位十六进制，HTTPS 保护传输，服务器 `.env` 仅保存其 `SUBSCRIPTION_TOKEN_SHA256`。它是持有者凭据，完整订阅地址应通过私密渠道交付，不应进入 Git、截图或原始请求日志。VPN 节点另外使用 UUID/密码认证。

## 节点与出口

| 节点 | 公网入口 | 服务 |
| --- | --- | --- |
| VLESS TCP TLS | `8443/tcp` | Xray |
| VLESS WebSocket TLS | `443/tcp` | nginx → `127.0.0.1:10086` Xray |
| Hysteria2 | `8443/udp` | sing-box |

WebSocket 与 Hysteria2 分别由 `MOBILE_WS_ENABLED`、`HYSTERIA2_ENABLED` 控制。订阅在服务器端解析节点 IPv4，保留 TLS SNI/WS Host，解析失败返回 503；DNS 使用经认证节点发送的 DoH，策略组不提供 `DIRECT`。双栈 TUN 与严格路由接管流量，VLESS 使用 XUDP 承载 UDP。

默认选择 `AUTO`。桌面和手机每 30 秒自动测速，以 30 ms 容差减少频繁切换；桌面检查超时为 3 秒。测速反映该探测目标的延迟，不等同于下载带宽或所有网站的延迟。HY2 默认省略固定带宽声明，使用自适应 BBR；只有测得实际链路能力后，才同时设置两个正值带宽参数。

Clash 全局模式的 `GLOBAL` 默认直接选择 `AUTO`，避免经过被手动固定的 `PROXY` 节点而失去自动选择。客户端可能保存旧配置和手动选择；更新订阅后需确认实际 `AUTO` 为 30 秒 `url-test`，并将 `GLOBAL` 与 `PROXY` 均选为 `AUTO`。手动选择单个节点时，不具备自动故障切换。

服务器出口和下一跳以 `ip -4 route get 1.1.1.1`、`ip -6 route` 为准，不同服务商可能使用公网网关、内网网关或 IPv6 隧道。HTTP token 在订阅服务验证，VPN 凭据在节点验证。客户端关闭 VPN 后仍需操作系统断网策略才能阻止直连；WebRTC 私网地址展示与真实公网出口泄露应分别验证。

订阅请求共享受限的持久数据库连接，只读取生成配置所需字段，每次重新检查账号启用状态与有效期；访问时间至多每分钟更新一次。两种格式都启用双栈解析，DNS 经认证代理发送，不使用客户端直连 DNS 回退。订阅接口的每 IP 限流返回 HTTP 429，仅限制配置下载，不限制已经建立的 VPN 业务流量。

## 目录与部署

- `api/vpn/`：统一格式路由、两种订阅输出及元数据接口；旧 WireGuard 下载接口固定返回 HTTP 410。
- `private/`：环境配置与共享订阅认证。
- `database/`：表结构和初始化说明；生产记录不提交 Git。
- `deploy/`、`docs/`：nginx 示例和部署说明。

运行环境为 nginx、PHP-FPM、MySQL/MariaDB、Xray、sing-box，由 systemd 管理。发布到 `/var/www/IP-sub-node`，从 `.env.example` 配置数据库、认证哈希和节点凭据，并将 `.env` 权限设为 `600`。仅在新数据库导入 `database/schema.sql`；该文件会重建 `vpn_clients`，不得导入已有生产表。

## 检查

```sh
find api private -name '*.php' -print0 | xargs -0 -n1 php -l
```

以下命令从终端静默读取 token，只显示 HTTP 状态，不输出节点凭据；关闭 shell 调试后执行：

```sh
set +x
VPN_BASE_URL='https://DOMAIN'
IFS= read -r -s SUBSCRIPTION_TOKEN
printf 'header = "Authorization: Bearer %s"\n' "$SUBSCRIPTION_TOKEN" |
    curl --config - --silent --show-error --fail -A 'mihomo' \
        --output /dev/null --write-out 'HTTP %{http_code}\n' "$VPN_BASE_URL/sub"
unset SUBSCRIPTION_TOKEN
```

部署验收还需验证错误 token 拒绝、两种客户端导入、逐节点 TCP/UDP、DNS、公网 IPv4/IPv6 出口及客户端实际运行配置。
