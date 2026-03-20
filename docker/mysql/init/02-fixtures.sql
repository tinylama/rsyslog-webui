USE rsyslogdb;

-- Deterministic fixture dataset used by the app and E2E tests.
-- Priorities 3..7 are included to keep the progress bars stable.

INSERT INTO SystemEvents
  (ReceivedAt, DeviceReportedTime, Facility, Priority, FromHost, SysLogTag, Messagetype, processid, Message)
VALUES
  ('2026-03-20 10:00:00', '2026-03-20 09:59:00', 4, 7, 'host-1', 'sshd', 'SYSLOG', 1001, 'Connection refused while connecting to 10.0.0.1'),
  ('2026-03-20 10:01:00', '2026-03-20 10:00:00', 2, 6, 'host-2', 'nginx', 'SYSLOG', 1002, 'Connection refused when proxying request'),
  ('2026-03-20 10:02:00', '2026-03-20 10:01:00', 16, 5, 'host-3', 'systemd', 'SYSLOG', 1003, 'Service started successfully'),
  ('2026-03-20 10:03:00', '2026-03-20 10:02:00', 9, 4, 'host-4', 'cron', 'SYSLOG', 1004, 'disk nearly full'),
  ('2026-03-20 10:04:00', '2026-03-20 10:03:00', 1, 3, 'host-5', 'rsyslog', 'SYSLOG', 1005, 'Connection refused occurred in rsyslog pipeline');

-- Extra rows for keyword search stability
INSERT INTO SystemEvents
  (ReceivedAt, DeviceReportedTime, Facility, Priority, FromHost, SysLogTag, Messagetype, processid, Message)
VALUES
  ('2026-03-20 10:05:00', '2026-03-20 10:04:00', 4, 6, 'host-2', 'nginx', 'SYSLOG', 1006, 'Connection refused again, retrying...');

