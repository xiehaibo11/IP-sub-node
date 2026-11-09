# Production VPN Subscription Runbook

本文档说明统一订阅认证、服务器部署和流量排障。订阅由 nginx/PHP-FPM 提供，节点由 Xray 和 sing-box 承载，数据库使用 MySQL/MariaDB，各服务由 systemd 管理。操作生产环境时保留现有 `.env`、数据库和用户正在使用的节点凭据。

## 1. 统一地址与格式选择

所有客户端使用同一个 HTTPS 地址：

```text
https://DOMAIN/sub/<64位小写十六进制token>
```

同一份有效凭据授权整个节点集合。Clash/Mihomo 客户端获得 YAML，Hiddify/sing-box 客户端获得 JSON；两个可选协议启用后，两种格式都包含 VLESS TCP TLS、VLESS WebSocket TLS 和 Hysteria2 三个节点。

`api/vpn/auto.php` 的格式选择顺序为：

1. `format=clash|mihomo|clash-meta` 或 `format=hiddify|sing-box|singbox`。
2. User-Agent 中的 Hiddify/sing-box 或 Clash/Mihomo 标识。
3. Accept 包含 `application/json` 时返回 sing-box JSON。
4. 其余请求默认返回 Clash YAML。

例如不识别客户端标识时，可在同一订阅地址后加 `?format=singbox`。格式参数不替代认证。

以下入口共享 `private/subscription_auth.php` 门禁：

| 入口 | 认证方式 |
| --- | --- |
| `/sub/<token>` | 路径 token；可协商格式 |
| `/sub`、`/api/vpn/auto.php` | Bearer 或 query token；可协商格式 |
| `/sub/client.yaml`、`/api/vpn/clash.php` | Bearer 或 query token；固定 YAML |
| `/sub/mobile.json`、`/api/vpn/hiddify.php` | Bearer 或 query token；固定 JSON |
| `/sub/<token>/client.yaml`、`/sub/<token>/mobile.json` | 验证路径 token；固定格式 |
| `/api/vpn/info.php` | 相同 Bearer 或 query token；元数据 JSON |

认证优先使用 `Authorization: Bearer <token>`，其次 `?token=<token>`，最后读取受支持路径中的 token。提供多个来源时应保持一致；优先来源错误不会尝试其他凭据。旧数据库 token 不自动获得访问权，旧路径中的 token 必须匹配当前部署哈希。

缺失或错误凭据返回 401；认证哈希未配置或非法返回 503；认证通过但没有启用且未过期的 `vpn_clients` 记录时返回 404。未定义路径仍可由 nginx 返回 404。`api/vpn/sub.php` 是已停用的 WireGuard 下载接口，固定返回 410。

## 2. Token 生成、存储与轮换

使用密码学随机数生成 32 个字节，编码为 64 位小写十六进制 token。HTTPS 提供传输加密；服务端只保存 SHA-256 哈希并进行恒定时间比较。

在私密管理终端生成变量，不回显 token：

```sh
set +x
SUBSCRIPTION_TOKEN="$(openssl rand -hex 32)"
SUBSCRIPTION_TOKEN_SHA256="$(printf '%s' "$SUBSCRIPTION_TOKEN" | openssl dgst -sha256 -r | cut -d ' ' -f 1)"
```

通过私密渠道将 token/完整订阅地址交付客户端；将哈希写入服务器 `.env` 的 `SUBSCRIPTION_TOKEN_SHA256`，不要将 token 本体写入 `.env`、Git、文档或日志。管理终端完成交付后清除变量。节点 UUID、HY2 密码、数据库密码继续按各自配置管理，不能用订阅哈希替代。

轮换时生成新 token，替换服务端哈希并确认新的 HTTP 请求读取了新配置，向客户端更新统一地址，然后确认旧 token 已返回 401。当前实现仅接受一个部署级 token。轮换订阅 token 会阻止旧订阅继续下载，但不会撤销已下载的节点凭据；泄露节点配置时还须同步轮换 Xray/sing-box 与 `.env` 的节点认证信息。

## 3. 请求和中转路径

```text
客户端 HTTPS /sub/<token>
  -> nginx -> auto.php -> 验证 SHA-256
  -> 选择首条启用且未过期的 vpn_clients 记录
  -> 根据客户端格式输出全部已启用节点

客户端业务 TCP/UDP + DNS
  -> 客户端 TUN / 本机代理入口
  -> VLESS UUID 或 Hysteria2 密码认证
  -> nginx + 本机 Xray / Xray / sing-box
  -> 服务器系统路由 -> 实际网络接口与网关
  -> 公网目标
```

WebSocket 路径是 `443/tcp nginx -> 127.0.0.1:10086 Xray`。VLESS TCP TLS 直接到 `8443/tcp Xray`，Hysteria2 到 `8443/udp sing-box`。服务器中的 `freedom`/`direct` outbound 表示通过服务器网络栈访问目标，随后经过系统网关；它与客户端绕过 VPN 的 `DIRECT` 策略不同。

用 `ip -4 route get 1.1.1.1` 读取 IPv4 下一跳；它并非验证 HTTP token 的应用代理。订阅 token 在 PHP 验证，代理隧道 UUID/密码在节点验证。若改为另一台带用户名/密码的内网代理，需另外配置其协议、地址、认证与出站链路，并验证没有服务器直连回退。

IPv6 下一跳应单独读取系统路由，不能把 IPv4 网关验证当成 IPv6 网关验证。DNS 公共解析器和目标网站应看到服务器出口；VPN 服务器作为连接对端仍会看到客户端来源 IP。

需要双栈出口时，不要给 Xray Freedom 同时设置固定 IPv4 `sendThrough` 与 `sockopt.domainStrategy: ForceIPv4`，否则只有 AAAA 的目标无法连接。需要优先 IPv4 时可使用 `UseIPv4v6`，并由系统为对应地址族选择源地址；修改前后分别测试 TCP、WS 的 IPv4/IPv6，不能用 HY2 的成功替代它们的验证。

## 4. 端口与环境配置

| 端口 | 服务 | 限制 |
| --- | --- | --- |
| `443/tcp` | nginx HTTPS/WSS | 对公网开放 |
| `8443/tcp` | Xray VLESS TLS | 对公网开放，验证 UUID |
| `8443/udp` | sing-box HY2 | 对公网开放，验证密码 |
| `127.0.0.1:10086/tcp` | Xray VLESS WS | 仅本机 nginx 可连接 |
| `22/tcp` | SSH | 限定管理来源 |
| 本机数据库/PHP-FPM | MariaDB/socket | 不对公网提供访问 |

按实际应用补充 ACME 证书更新所需的 80/tcp；80 上的订阅路径不接收凭据，也不通过重定向处理凭据。客户端必须直接使用 HTTPS。

仅首次部署时复制 `.env.example`，已有生产环境应更新必要字段而不是覆盖整个文件：

```sh
cp .env.example .env
sudo chown www-data:www-data .env
chmod 600 .env
```

将属主替换为实际 PHP-FPM pool 用户；`600` 文件必须可被该用户读取。只更改权限而保留 `root` 属主会让 PHP 无法加载认证哈希，订阅返回 503。

必须配置 `DB_HOST`、`DB_USER`、`DB_PASSWORD`、`DB_NAME`、`SUBSCRIPTION_TOKEN_SHA256`，以及 `SUBSCRIPTION_NODE_UUID/HOST/PORT/SNI/NAME`。要分发完整三节点集合，还需：

- `MOBILE_WS_ENABLED=true`，配置 `MOBILE_WS_PORT/LOCAL_PORT/PATH/HOST/SNI/NAME`。
- `HYSTERIA2_ENABLED=true`，配置 `HYSTERIA2_PORT/PASSWORD/OBFS_PASSWORD/NAME` 和带宽参数。

`MOBILE_WS_PATH` 必须同时匹配 `.env`、nginx exact location、Xray `wsSettings.path`。证书应覆盖 SNI 域名；不要关闭 TLS 证书校验。现有订阅不设置 Vision flow，服务端也不要单方面要求 `xtls-rprx-vision`。

## 5. 数据库与服务部署

项目发布目录为 `/var/www/IP-sub-node`。仅在空数据库导入 `database/schema.sql`，它包含 `DROP TABLE`，不能用于更新已有生产数据库。通过私有管理渠道配置至少一条启用且未过期的 `vpn_clients` 记录，并满足当前表的非空约束。

两个 renderer 与 info 都按 id 使用首条有效记录。数据库中的旧 WireGuard 字段和旧 token 字段为兼容结构；订阅访问授权由 `.env` 中的部署级哈希控制。生产行数据不得提交 Git。

使用 `deploy/nginx-secure-example.conf` 配置实际域名、证书、PHP-FPM socket 和 WS path。务必部署 `private/subscription_auth.php`，并将 `HTTP_AUTHORIZATION` 转发给 PHP-FPM。仅开放所需端点，其余路径返回 404；`.env`、数据库文件和 private 目录不能通过站点访问。

Xray 提供 TLS 8443 与 loopback WS 10086 两个入站，UUID 与 `.env` 一致。sing-box 提供 HY2 UDP 8443，用户密码、salamander 密码和证书配置与 `.env` 一致。systemd unit 与配置文件路径以实际 `ExecStart` 为准；当前服务名使用 `sing-box`，不要假设存在 `sing-box@hy2` 模板实例。

变更后先验证，再 reload/restart 受影响服务；不要为仅 PHP 输出变更重启所有 VPN 核心：

```sh
sudo nginx -t
sudo xray run -test -c /usr/local/etc/xray/config.json
sudo sing-box check -c /etc/sing-box/config.json
systemctl is-active nginx xray sing-box php8.3-fpm mariadb
ip -4 route get 1.1.1.1
ip -6 route
```

其中路径、PHP 版本和数据库 unit 名需按服务器实际安装调整。只有 nginx 配置变化时执行 `sudo systemctl reload nginx`；核心配置变化时，在原生校验通过后重启相应 unit 并复查监听与连通性。

默认策略选择 `AUTO`，不要把存在自动组等同于当前已使用它。Mihomo 的 `AUTO` 使用 `url-test`：每 30 秒检查一次，单次超时 3 秒、要求 HTTP 204，切换容差为 30 ms。手机端使用 30 秒 `urltest`、30 ms 容差，并保留已有连接。测速只比较探测目标的延迟；它不测下载带宽，也不保证每个目标都获得最低延迟。手动指定单个节点时，该连接不会自动改走其他节点。

Mihomo 全局模式的 `GLOBAL` 首选 `AUTO`，直接进入健康检查与自动选择，不依赖 `PROXY` 的手动选择。客户端更新订阅后还应检查内核实际生效的组类型、检查间隔与 `now`：`AUTO` 应为 `url-test`、间隔 30 秒，`GLOBAL` 和 `PROXY` 应选择 `AUTO`。Clash Verge 的应用设置与 DNS 覆写可能覆盖订阅参数，应读取内核 `/configs`、策略组和实际出口。只修改订阅源文件或只看到下载成功，不能证明运行内核已加载新配置。

`private/subscription_client.php` 复用每个 PHP worker 的数据库连接；每次下载都重新检查 enabled/expiry，避免缓存撤销决定。SELECT 不再读取旧 VPN 私钥字段；访问时间按 60 秒节流，并用 UPDATE 条件防止并发重复写入。PHP OPcache 可缓存脚本编译结果，部署后必须确认新代码已生效。

高并发 WS 需要同时检查 nginx 的 `worker_connections` 与 worker 文件句柄上限：每个代理连接至少消耗客户端、上游两个连接。可在 main 上下文设置 `worker_rlimit_nofile 65536;`，events 中设置 `worker_connections 8192;`，服务 LimitNOFILE 不低于该值；原生校验通过后 reload 并读取实际 worker limits。该配置是资源上限，不是并发用户数保证。TLS 会话缓存与 WS 无缓冲配置见 nginx 模板。订阅 HTTP 限流保留 10 r/s、burst 20，明确返回 429，不能用放宽订阅限流替代代理吞吐优化。

QUIC 的 UDP socket 缓冲区受 Linux `net.core.rmem_max/wmem_max` 限制。依据 quic-go 文档与实测调整上限，不必同步放大默认分配量；修改后重建 QUIC socket 并验证丢包与吞吐。带宽时延积 `带宽(bit/s) × RTT(s) / 8` 用于估算在途数据量，不等于应为每个 socket 分配相同内存，也不能把虚拟机回环吞吐当成公网带宽。

`HYSTERIA2_UP_MBPS` 与 `HYSTERIA2_DOWN_MBPS` 默认均为 `0`，两份订阅会省略固定带宽字段并使用自适应 BBR。只有两项都为正值时才声明固定带宽；应依据实测链路设置，不能把该声明当作云服务器已购买的带宽。网络波动排查需同时比较客户端到节点与服务器到公网的延迟、丢包和错误，避免只根据历史累计重传调内核参数。

## 6. DNS、UDP 与本地 IP 防泄露

两种订阅都在服务器上将节点地址解析为 IPv4，再把该 IP 固定到生成配置；TLS SNI 和 WS Host 保留域名。解析失败返回 503，避免客户端为建立首条隧道而通过本地 DNS 查询节点。节点 IP 发生变化时需刷新订阅。

Clash/Mihomo 使用 fake-ip；默认 DNS、节点 DNS 和业务 DNS 均显式使用经 `PROXY` 的 IP 地址 DoH。`respect-rules` 配合非空 `proxy-server-nameserver`，避免 bootstrap 循环。fake-ip-filter 只决定返回真实地址还是 fake-ip，不授予直连权限。策略组没有 `DIRECT`，最终规则为 `MATCH,PROXY`，且不保存旧的手动直连选择。

sing-box 使用 typed HTTPS DNS server，地址为 IP、TLS server name 为解析器域名，并显式 `detour` 到最终认证节点或策略组。端口 53 请求通过 `hijack-dns` 进入该 DNS 模块，不使用直连 UDP DNS。两个 VLESS outbound 不限制为 TCP-only，保留 XUDP；底层 TCP/WS 传输仍照常使用。

两端 TUN 均包含 IPv4/IPv6 接管和严格路由；Clash DNS 包含 IPv6 fake-ip 范围，sing-box 使用 prefer_ipv4 并保留 AAAA 解析。客户端必须实际开启 TUN/VPN，核对其有效配置，防止 UI、merge、排除应用或路由绕过覆盖订阅。配置应使用实际安装的核心进行校验，不照抄较新版本专有字段。

严格路由只覆盖核心运行期间已接管的流量。VPN 关闭、核心崩溃或系统恢复普通路由后，要继续阻止直连，必须启用操作系统支持的断网策略。例如 Android 使用“始终开启 VPN”与“阻止未使用 VPN 的连接”；macOS/Windows 需要独立的系统防火墙或客户端提供的持久断网功能，并测试退出、重启和换网场景。[Android VPN 文档](https://developer.android.com/develop/connectivity/vpn#blocked_connections)

WebRTC 检查要区分：网页通过 STUN 得到的真实公网 IP，和浏览器上报的 `192.168.*` 等私网 host candidate。隧道接管前者；后者属于浏览器地址暴露策略，可能还需浏览器限制非代理 UDP/本地候选。仅封锁几个 STUN 端口不足以证明防泄露。[RFC 8828](https://datatracker.ietf.org/doc/html/rfc8828#section-5.2)

## 7. 不泄露凭据的验证

先运行 PHP 语法检查：

```sh
find api private -name '*.php' -print0 | xargs -0 -n1 php -l
```

以下为 Bash 示例。先关闭调试，再从终端静默读取 token；不将 token 写入命令历史、进程参数中的 URL 或输出。临时配置含节点凭据，必须权限受限并在结束后删除：

```bash
set +x
umask 077
VPN_BASE_URL='https://DOMAIN'
IFS= read -r -s SUBSCRIPTION_TOKEN
VPN_CHECK_DIR="$(mktemp -d)"
trap 'rm -rf "$VPN_CHECK_DIR"; unset SUBSCRIPTION_TOKEN' EXIT

curl --silent --output /dev/null --write-out 'unauthenticated: %{http_code}\n' "$VPN_BASE_URL/sub"
printf 'header = "Authorization: Bearer %s"\n' "$(printf '%064d' 0)" |
    curl --config - --silent --output /dev/null \
        --write-out 'wrong token: %{http_code}\n' "$VPN_BASE_URL/sub"

printf 'header = "Authorization: Bearer %s"\n' "$SUBSCRIPTION_TOKEN" |
    curl --config - --silent --show-error --fail -A 'mihomo' \
        --output "$VPN_CHECK_DIR/client.yaml" \
        --write-out 'Clash: %{http_code}\n' "$VPN_BASE_URL/sub"
printf 'header = "Authorization: Bearer %s"\n' "$SUBSCRIPTION_TOKEN" |
    curl --config - --silent --show-error --fail -A 'Hiddify' \
        --output "$VPN_CHECK_DIR/mobile.json" \
        --write-out 'sing-box: %{http_code}\n' "$VPN_BASE_URL/sub"

mihomo -t -d "$VPN_CHECK_DIR" -f "$VPN_CHECK_DIR/client.yaml"
sing-box check -c "$VPN_CHECK_DIR/mobile.json"
rm -rf "$VPN_CHECK_DIR"
unset SUBSCRIPTION_TOKEN
trap - EXIT
```

预期前两个状态为 401，后两个为 200。不要用 `curl -v`、`set -x`、`cat` 或 `sed` 把认证头、完整订阅 URL、UUID、HY2 密码和生成配置打印到共享终端。路径 token、query token 和 info 接口也要验证；日志或测试报告只保留状态、节点数量和成功/失败结论。

完整验收还应包括：

- 同一条 `/sub/<token>` 在实际 Clash/Hiddify 中均可导入，并显示全部三个节点；Accept/format 协商符合预期。
- 错误、缺失、撤销 token 不能下载任何格式或 info，敏感路径返回 404，WireGuard 返回 410。
- 三个节点分别测试 TCP、UDP 和公网 IPv4/IPv6 出口；DNS 经认证隧道，服务器路由经过预期网关。
- 使用实际浏览器检查 WebRTC 公网候选及私网候选，分别记录结论。
- 测试节点故障时不回退客户端直连；独立验证 VPN 退出、核心崩溃和换网后的系统断网策略。

下载成功、核心配置校验通过、服务 active 与真实业务请求成功是不同的验证结果，应分别确认。

## 8. 日志与常见故障

nginx 使用模板中的脱敏 `vpn_safe` 日志格式：屏蔽路径 token，排除 query string、Authorization 和 Referer。PHP endpoint 使用受限错误日志策略，避免上游错误自动拼入原始订阅 URL。前置 CDN、负载均衡器和监控也应检查相同字段；不要把未经脱敏的历史请求日志复制到工单。保留必要的服务错误计数与状态，避免靠打印请求凭据排障。

WS 连接成功时可在脱敏访问日志中看到 101；检查核心日志时仅在私密终端读取，不转贴来源 IP、节点凭据或原始请求。实际监听可用 `sudo ss -lntup` 核对，10086 必须只绑定 loopback。

| 现象 | 排查与处理 |
| --- | --- |
| 订阅 401 | 核对 token 来源和当前哈希；检查 PHP-FPM 是否收到 Authorization，不打印凭据 |
| 订阅 503 | 检查哈希格式、节点配置和服务器侧 IPv4 解析，不添加本地 DNS 回退 |
| 订阅 404 | 先区分未定义路径与有效认证后无可用数据库记录 |
| 客户端拿错格式 | 使用正确 User-Agent/Accept，或在统一地址加 `format=clash|singbox` |
| 443 WS 返回 404/502 | 核对三处 WS path 及本机 Xray 10086 监听 |
| VLESS TCP 可用但 UDP 失败 | 核对 XUDP、服务端 UDP 支持与客户端实际配置，避免误加 TCP-only 限制 |
| HY2 在移动网络超时 | 核对 UDP 8443、安全组与运营商限制；选择同一订阅中的 WS 443 节点 |
| 实际出口仍是本地 IP | 核对 TUN/VPN 是否生效、IPv6、排除应用、DNS detour、客户端覆盖配置和浏览器 WebRTC |
| 轮换后旧节点仍能用 | 订阅 token 只控制下载，需另外轮换已经泄露的节点 UUID/密码 |

新服务器按“依赖安装、环境与认证哈希、空库初始化、nginx/核心配置、原生校验、服务启动、认证检查、客户端导入、逐节点出口”顺序部署。生产变更完成后清理临时生成配置，并以实际请求结果确认服务恢复。
