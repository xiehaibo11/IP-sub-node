VPN 节点部署文档
本文档说明如何在一台 Linux 服务器上部署一个标准 WireGuard VPN 节点。流程只涉及 VPN 隧道、转发、防火墙和客户端配置。

适用范围
系统：Ubuntu 22.04/24.04 或 Debian 12。
节点角色：公网 VPN 服务端。
协议：WireGuard UDP。
服务端 VPN 网段：10.66.0.0/24。
服务端 VPN 地址：10.66.0.1/24。
默认监听端口：51820/udp。
下面命令默认以 root 执行。非 root 用户请在命令前加 sudo。

1. 准备服务器
确认服务器具备：

一个公网 IPv4 地址。
云安全组已放行 51820/udp。
系统防火墙已允许 51820/udp。
内核支持 WireGuard。Ubuntu 22.04/24.04 和 Debian 12 默认支持。
查看默认公网网卡名：

ip route show default
输出示例：

default via 102.204.223.1 dev ens17 proto static
这里的公网网卡名是 ens17。后续配置中的 ens17 需要替换成你的实际网卡名。

2. 安装 WireGuard
apt update
apt install -y wireguard iptables qrencode
确认命令可用：

wg --version
3. 开启 IPv4 转发
写入内核参数：

cat >/etc/sysctl.d/99-wireguard.conf <<'EOF'
net.ipv4.ip_forward=1
EOF
sysctl --system
确认结果为 1：

sysctl net.ipv4.ip_forward
4. 生成服务端密钥
install -d -m 700 /etc/wireguard
cd /etc/wireguard
wg genkey | tee server_private.key | wg pubkey > server_public.key
chmod 600 server_private.key
cat server_public.key
记录输出的服务端公钥，客户端配置会用到。

5. 创建服务端配置
将下面配置写入 /etc/wireguard/wg0.conf，并替换：

SERVER_PRIVATE_KEY：cat /etc/wireguard/server_private.key 的结果。
ens17：你的公网网卡名。
[Interface]
Address = 10.66.0.1/24
ListenPort = 51820
PrivateKey = SERVER_PRIVATE_KEY

PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -A FORWARD -o wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -s 10.66.0.0/24 -o ens17 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -D FORWARD -o wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -s 10.66.0.0/24 -o ens17 -j MASQUERADE
建议文件权限：

chmod 600 /etc/wireguard/wg0.conf
6. 启动 VPN 节点
systemctl enable --now wg-quick@wg0
systemctl status wg-quick@wg0 --no-pager
wg show
如果使用 ufw：

ufw allow 51820/udp
ufw reload
如果使用云服务器控制台安全组，也需要在控制台放行 51820/udp。

7. 添加客户端
以下示例添加第一个客户端 client1，客户端地址为 10.66.0.2/32。

生成客户端密钥：

cd /etc/wireguard
wg genkey | tee client1_private.key | wg pubkey > client1_public.key
chmod 600 client1_private.key
把客户端加入服务端配置：

cat >>/etc/wireguard/wg0.conf <<'EOF'

[Peer]
PublicKey = CLIENT1_PUBLIC_KEY
AllowedIPs = 10.66.0.2/32
EOF
替换 CLIENT1_PUBLIC_KEY：

CLIENT1_PUBLIC_KEY=$(cat /etc/wireguard/client1_public.key)
sed -i "s|CLIENT1_PUBLIC_KEY|$CLIENT1_PUBLIC_KEY|" /etc/wireguard/wg0.conf
systemctl restart wg-quick@wg0
wg show
8. 生成客户端配置
创建 /etc/wireguard/client1.conf：

SERVER_PUBLIC_KEY=$(cat /etc/wireguard/server_public.key)
CLIENT1_PRIVATE_KEY=$(cat /etc/wireguard/client1_private.key)
SERVER_PUBLIC_IP="你的服务器公网IP"

cat >/etc/wireguard/client1.conf <<EOF
[Interface]
PrivateKey = ${CLIENT1_PRIVATE_KEY}
Address = 10.66.0.2/32
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = ${SERVER_PUBLIC_KEY}
Endpoint = ${SERVER_PUBLIC_IP}:51820
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
chmod 600 /etc/wireguard/client1.conf
说明：

AllowedIPs = 0.0.0.0/0 表示客户端所有 IPv4 流量都走 VPN。
如果只希望访问内网，不接管全部流量，可改成 AllowedIPs = 10.66.0.0/24 或你的内网网段。
PersistentKeepalive = 25 适合客户端在 NAT 网络后面时保持连接。
手机端可以用二维码导入：

qrencode -t ansiutf8 </etc/wireguard/client1.conf
电脑端可直接导入 client1.conf。

9. 连通性测试
客户端连接后，在服务器上查看握手：

wg show
客户端测试：

ping 10.66.0.1
curl ifconfig.me
如果 curl ifconfig.me 返回服务器公网 IP，说明客户端公网出口已经经过 VPN 节点。

10. 常用运维命令
查看状态：

systemctl status wg-quick@wg0 --no-pager
wg show
重启服务：

systemctl restart wg-quick@wg0
停止服务：

systemctl stop wg-quick@wg0
查看日志：

journalctl -u wg-quick@wg0 -n 100 --no-pager
开机自启：

systemctl enable wg-quick@wg0
11. 删除客户端
编辑 /etc/wireguard/wg0.conf，删除对应客户端的 [Peer] 段，然后重启：

systemctl restart wg-quick@wg0
wg show
也可以保留配置文件，只移除运行中的 peer：

wg set wg0 peer CLIENT_PUBLIC_KEY remove
但这种方式不会修改 /etc/wireguard/wg0.conf，服务重启后 peer 会再次生效。

12. 多客户端规划
每个客户端必须使用唯一的 VPN 地址：

客户端	VPN 地址
client1	10.66.0.2/32
client2	10.66.0.3/32
client3	10.66.0.4/32
不要让两个客户端共用同一个私钥或同一个 AllowedIPs 地址。

13. 安全建议
不要把 /etc/wireguard/*.key 和客户端配置提交到 Git。
服务端私钥只保存在服务器 /etc/wireguard/server_private.key。
每个客户端单独生成密钥，人员离职或设备丢失时只删除对应 peer。
只开放 51820/udp 和必要的 SSH 管理端口。
SSH 建议关闭密码登录，只使用密钥登录。
定期执行系统更新：
apt update
apt upgrade -y
14. 故障排查
客户端没有握手
检查：

wg show
ss -lunp | grep 51820
重点确认：

云安全组是否放行 端口/udp。
系统防火墙是否放行 端口/udp。
客户端 Endpoint 是否是正确的公网 IP 或域名。
客户端时间是否准确。
能连上但不能访问公网
检查 IPv4 转发：

sysctl net.ipv4.ip_forward
检查 NAT 规则：

iptables -t nat -S | grep 10.66.0.0
确认 PostUp/PostDown 里的公网网卡名正确。

能访问公网但 DNS 不通
把客户端配置中的 DNS 改为可用解析器：

DNS = 1.1.1.1, 8.8.8.8
重新导入或重启客户端连接。

15. 备份和迁移
备份服务端配置：

tar czf wireguard-backup.tgz /etc/wireguard
chmod 600 wireguard-backup.tgz
迁移到新服务器后：

tar xzf wireguard-backup.tgz -C /
systemctl enable --now wg-quick@wg0
如果新服务器公网 IP 变化，需要更新所有客户端配置中的 Endpoint。

启用PM2管理进程

订阅分发地址：

桌面客户端 Clash/Mihomo，VLESS TCP TLS：

https://bcbbs3.cn/sub/client.yaml

手机端 Hiddify/sing-box，VLESS TCP TLS：

https://bcbbs3.cn/sub/mobile.json

说明：

两个订阅地址固定走不同输出格式，不再依赖 User-Agent 自动判断。
节点服务由 Xray 监听 `8443/tcp`，TLS 证书使用 `bcbbs3.cn`。
如果需要按用户分发，把 64 位 token 放在路径中：

https://bcbbs3.cn/sub/TOKEN/client.yaml
https://bcbbs3.cn/sub/TOKEN/mobile.json
