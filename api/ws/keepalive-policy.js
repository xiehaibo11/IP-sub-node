const ACTIVE_WINDOW = 45 * 1000;
const PROBE_WINDOW = 120 * 1000;
const STATUS_RETENTION = 300 * 1000;
const CHECK_INTERVAL = 30 * 1000;
const PROBE_INTERVAL = 30 * 1000;
const MAX_PROBE_COUNT = 3;
const WS_OPEN = 1;

function buildKeepalivePayload(now) {
  return {
    type: "connected",
    reason: "keepalive",
    kdate: now
  };
}

function evaluateKeepaliveState({
  now,
  lastPing,
  probeInfo,
  hasSocket,
  readyState
}) {
  if (!hasSocket || readyState === 3) {
    return { action: "disconnect", status: "offline" };
  }

  if (!lastPing) {
    return { action: "disconnect", status: "offline" };
  }

  const silence = now - lastPing;

  if (silence <= ACTIVE_WINDOW) {
    return { action: "healthy", status: "online" };
  }

  if (silence <= PROBE_WINDOW) {
    if (!probeInfo || now - probeInfo.time >= PROBE_INTERVAL) {
      const nextProbeCount = (probeInfo?.count || 0) + 1;
      if (nextProbeCount >= MAX_PROBE_COUNT) {
        return { action: "disconnect", status: "offline", nextProbeCount };
      }
      return { action: "probe", status: "probing", nextProbeCount };
    }

    return { action: "wait", status: "probing" };
  }

  return { action: "disconnect", status: "offline" };
}

module.exports = {
  ACTIVE_WINDOW,
  PROBE_WINDOW,
  STATUS_RETENTION,
  CHECK_INTERVAL,
  PROBE_INTERVAL,
  MAX_PROBE_COUNT,
  WS_OPEN,
  buildKeepalivePayload,
  evaluateKeepaliveState
};
