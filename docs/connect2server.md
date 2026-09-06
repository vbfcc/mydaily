# Connect to Production Server

## SSH Config

```
Host Factorland-Iran
  HostName 185.8.174.229
  User root
  IdentityFile C:/Users/Padidar/.ssh/id_factorland_iran
  ServerAliveInterval 20
  ServerAliveCountMax 3
  TCPKeepAlive yes
  ConnectTimeout 10
```

## Production Path (Laravel API)

```
/var/www/html/api.factorland.ir
```
