FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends rsyslog rsyslog-mysql dbconfig-no-thanks ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY rsyslog.conf /etc/rsyslog.conf

EXPOSE 5514/tcp 5514/udp

CMD ["rsyslogd", "-n", "-f", "/etc/rsyslog.conf"]
