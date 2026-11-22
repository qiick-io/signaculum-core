# TLS terminator in front of Signaculum for the OpenID Foundation suite.
FROM nginx:1.27-alpine

RUN apk add --no-cache openssl \
    && mkdir -p /etc/ssl/private \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/ssl/private/nginx-selfsigned.key \
        -out /etc/ssl/certs/nginx-selfsigned.crt \
        -subj "/CN=signaculum-proxy" \
        -addext "subjectAltName=DNS:signaculum-proxy,DNS:localhost,IP:127.0.0.1"

EXPOSE 8443
