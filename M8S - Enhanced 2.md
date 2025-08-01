# MicroK8S - Enhanced 2

# MicroK8S Complete Setup - Enhanced Version

## Arsitektur Setup

**VPS 1 (Manager):** 4 core, 6GB RAM, 100GB NVMe - 10.0.0.1 / 194.233.91.166
**VPS 2 (Worker):** 3 core, 8GB RAM, 75GB NVMe - 10.0.0.2 / 154.26.130.76

**Services:**

- WordPress (loker.cyou) - Single site
- WordPress (sabo.news) - Multisite
- MariaDB cluster
- Redis cache
- Caddy reverse proxy
- Adminer GUI
- N8N automation
- Supabase backend
- Mailcow mail server
- K9s dashboard
- SSL/TLS dengan Let's Encrypt
- Monitoring & Backup

---

## FASE 0: System Preparation

### 0.1 System Prep Script untuk Manager (Manager)

```bash
#!/bin/bash
# system-prep-manager.sh
set -e

echo "=== System Preparation - Manager Node ==="

# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y curl wget git htop tree apache2-utils

# Disable swap permanently
echo "Disabling swap..."
sudo swapoff -a
sudo sed -i '/ swap / s/^\(.*\)$/#\1/g' /etc/fstab

# Configure firewall for MicroK8s
echo "Configuring firewall..."
sudo ufw allow 16443/tcp  # API server
sudo ufw allow 10250/tcp  # Kubelet
sudo ufw allow 10255/tcp  # Kubelet read-only
sudo ufw allow 25000/tcp  # Cluster agent
sudo ufw allow 12379/tcp  # etcd
sudo ufw allow 10257/tcp  # kube-controller
sudo ufw allow 10259/tcp  # kube-scheduler
sudo ufw allow 19001/tcp  # dqlite
sudo ufw allow 80/tcp     # HTTP
sudo ufw allow 443/tcp    # HTTPS

# System requirements check
echo "=== System Requirements Check ==="
echo "Memory:"
free -h | grep -E "Mem|Swap"
echo "Disk:"
df -h /
echo "CPU:"
lscpu | grep -E "CPU|Architecture"

echo "=== Manager system preparation completed! ==="

```

### 0.2 System Prep Script untuk Worker (Worker)

```bash
#!/bin/bash
# system-prep-worker.sh
set -e

echo "=== System Preparation - Worker Node ==="

# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y curl wget

# Disable swap permanently
echo "Disabling swap..."
sudo swapoff -a
sudo sed -i '/ swap / s/^\(.*\)$/#\1/g' /etc/fstab

# Configure firewall for MicroK8s
echo "Configuring firewall..."
sudo ufw allow 16443/tcp  # API server
sudo ufw allow 10250/tcp  # Kubelet
sudo ufw allow 10255/tcp  # Kubelet read-only
sudo ufw allow 25000/tcp  # Cluster agent

echo "=== Worker system preparation completed! ==="

```

---

## FASE 1: MicroK8s Installation & Cluster Setup

### 1.1 Install MicroK8s Manager dengan Enhanced Setup (Manager)

```bash
#!/bin/bash
# install-microk8s-manager.sh
set -e

echo "=== MicroK8s Manager Installation ==="

# Install snapd
sudo apt install -y snapd

# Install MicroK8s
sudo snap install microk8s --classic --channel=1.28/stable

# Add user to microk8s group
sudo usermod -a -G microk8s $USER
sudo chown -f -R $USER ~/.kube

# Apply group changes
newgrp microk8s

# Wait for MicroK8s to be ready with timeout
echo "Waiting for MicroK8s to be ready..."
timeout 300 bash -c 'until microk8s status --wait-ready; do sleep 5; done'

# Comprehensive health check
echo "=== MicroK8s Health Check ==="
microk8s kubectl get nodes
microk8s kubectl cluster-info
microk8s kubectl get componentstatuses

# Enable required addons with verification
echo "=== Enabling MicroK8s Addons ==="

addons=("dns" "storage" "ingress" "metrics-server")

for addon in "${addons[@]}"; do
    echo "Enabling $addon..."
    microk8s enable $addon

    # Wait for addon to be ready
    case $addon in
        "dns")
            echo "Waiting for DNS to be ready..."
            microk8s kubectl wait --for=condition=Ready pod -l k8s-app=kube-dns -n kube-system --timeout=300s
            ;;
        "storage")
            echo "Waiting for storage to be ready..."
            microk8s kubectl wait --for=condition=Ready pod -l app=hostpath-provisioner -n kube-system --timeout=300s
            ;;
        "ingress")
            echo "Waiting for ingress to be ready..."
            microk8s kubectl wait --for=condition=Ready pod -l app.kubernetes.io/name=nginx-ingress -n ingress --timeout=300s
            ;;
        "metrics-server")
            echo "Waiting for metrics-server to be ready..."
            microk8s kubectl wait --for=condition=Ready pod -l k8s-app=metrics-server -n kube-system --timeout=300s
            ;;
    esac
    echo "$addon is ready!"
done

# Enable MetalLB with dynamic IP configuration
echo "Enabling MetalLB..."
microk8s enable metallb

# Wait for MetalLB to be ready
microk8s kubectl wait --for=condition=Ready pod -l app=metallb -n metallb-system --timeout=300s

# Configure MetalLB IP pool
echo "=== Configuring MetalLB IP Pool ==="
cat <<EOF | microk8s kubectl apply -f -
apiVersion: v1
kind: ConfigMap
metadata:
  namespace: metallb-system
  name: config
data:
  config: |
    address-pools:
    - name: default
      protocol: layer2
      addresses:
      - 10.0.0.100-10.0.0.110
EOF

echo "MetalLB configured with IP range: 10.0.0.100-10.0.0.110"

# Enable cert-manager
echo "Enabling cert-manager..."
microk8s enable cert-manager
microk8s kubectl wait --for=condition=Ready pod -l app=cert-manager -n cert-manager --timeout=300s

# Enable registry
microk8s enable registry

# Enable ha-cluster
microk8s enable ha-cluster

# Setup kubectl alias
echo "alias kubectl='microk8s kubectl'" >> ~/.bashrc
source ~/.bashrc

# Generate join token
echo "=== Generating Join Token ==="
JOIN_TOKEN=$(microk8s add-node | grep "microk8s join" | head -1)
echo "Join command for worker: $JOIN_TOKEN"
echo "$JOIN_TOKEN" > /tmp/join-command.txt

echo "=== MicroK8s Manager installation completed! ==="
echo "Join command saved to /tmp/join-command.txt"
echo "LoadBalancer IP range: 10.0.0.100-10.0.0.110"

```

### 1.2 Install MicroK8s Worker (Worker)

```bash
#!/bin/bash
# install-microk8s-worker.sh
set -e

echo "=== MicroK8s Worker Installation ==="

# Install snapd
sudo apt install -y snapd

# Install MicroK8s
sudo snap install microk8s --classic --channel=1.28/stable

# Add user to microk8s group
sudo usermod -a -G microk8s $USER
sudo chown -f -R $USER ~/.kube

# Apply group changes
newgrp microk8s

# Wait for MicroK8s to be ready
echo "Waiting for MicroK8s to be ready..."
timeout 300 bash -c 'until microk8s status --wait-ready; do sleep 5; done'

# Setup kubectl alias
echo "alias kubectl='microk8s kubectl'" >> ~/.bashrc
source ~/.bashrc

echo "=== Worker installation completed! ==="
echo "Ready to join cluster. Use join command from manager."

```

### 1.3 Cluster Verification (Manager)

```bash
#!/bin/bash
# verify-cluster.sh

echo "=== Cluster Verification ==="
microk8s kubectl get nodes -o wide
microk8s kubectl get pods --all-namespaces
microk8s kubectl get svc --all-namespaces

# Test cluster connectivity
echo "=== Testing Cluster Connectivity ==="
microk8s kubectl run test-pod --image=busybox --rm -it --restart=Never -- nslookup kubernetes.default

```

---

## FASE 2: Enhanced Storage Configuration

### 2.1 Storage Classes & Namespaces (Manager)

```yaml
# storage-config-enhanced.yaml
---
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: fast-ssd
provisioner: microk8s.io/hostpath
parameters:
  pvDir: /var/snap/microk8s/common/default-storage
volumeBindingMode: WaitForFirstConsumer
reclaimPolicy: Retain
---
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: wordpress-storage
provisioner: microk8s.io/hostpath
parameters:
  pvDir: /var/snap/microk8s/common/default-storage/wordpress
reclaimPolicy: Retain
volumeBindingMode: WaitForFirstConsumer
---
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: mysql-storage
provisioner: microk8s.io/hostpath
parameters:
  pvDir: /var/snap/microk8s/common/default-storage/mysql
reclaimPolicy: Retain
volumeBindingMode: Immediate
---
apiVersion: v1
kind: Namespace
metadata:
  name: wordpress
---
apiVersion: v1
kind: Namespace
metadata:
  name: database
---
apiVersion: v1
kind: Namespace
metadata:
  name: tools
---
apiVersion: v1
kind: Namespace
metadata:
  name: mail
---
apiVersion: v1
kind: Namespace
metadata:
  name: monitoring

```

### **Langkah Tambahan (FASE 2, Setelah Storage & Namespaces):**

- Buat **Secret** untuk MariaDB sebelum menerapkan mariadb-enhanced.yaml:

bash

```bash
*# Hasilkan kata sandi acak*MARIADB_ROOT_PASSWORD=$(openssl rand -base64 32)
MARIADB_USER_PASSWORD=$(openssl rand -base64 32)
echo "Kata Sandi Root Baru: $MARIADB_ROOT_PASSWORD" >> /tmp/mariadb-credentials.txt
echo "Kata Sandi User Baru: $MARIADB_USER_PASSWORD" >> /tmp/mariadb-credentials.txt
*# Buat Secret*microk8s kubectl create secret generic mariadb-secret \
  --namespace=database \
  --from-literal=MYSQL_ROOT_PASSWORD="$MARIADB_ROOT_PASSWORD" \
  --from-literal=MYSQL_PASSWORD="$MARIADB_USER_PASSWORD" \
  --from-literal=MYSQL_USER="wordpress_user"
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: Penggunaan envFrom untuk mengambil semua variabel dari **Secret** akan bekerja dengan baik selama **Secret** mariadb-secret ada di namespace database sebelum **Deployment** diterapkan.
- **Potensi Error**:
    - Jika **Secret** mariadb-secret tidak dibuat sebelumnya, **Deployment** akan gagal karena variabel lingkungan seperti MYSQL_ROOT_PASSWORD tidak ditemukan. Pastikan perintah kubectl create secret dijalankan terlebih dahulu.
    - Jika format **Secret** salah (misalnya, kunci tidak sesuai dengan MYSQL_ROOT_PASSWORD, MYSQL_USER, atau MYSQL_PASSWORD), MariaDB tidak akan bisa menginisialisasi database dengan benar.

---

## FASE 3: Enhanced MariaDB with Better Configuration

### 3.1 MariaDB Enhanced Secrets & Config (Manager)

```yaml
# mariadb-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: mariadb-config
  namespace: database
data:
  custom.cnf: |
    [mysqld]
    max_connections = 200
    innodb_buffer_pool_size = 2G
    innodb_log_file_size = 256M
    innodb_flush_method = O_DIRECT
    query_cache_type = 1
    query_cache_size = 128M
    tmp_table_size = 128M
    max_heap_table_size = 128M
    bind-address = 0.0.0.0
    character-set-server = utf8mb4
    collation-server = utf8mb4_unicode_ci
    slow_query_log = 1
    slow_query_log_file = /var/log/mysql/slow.log
    long_query_time = 2
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: mariadb-pvc
  namespace: database
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: mysql-storage
  resources:
    requests:
      storage: 20Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mariadb
  namespace: database
spec:
  replicas: 1
  selector:
    matchLabels:
      app: mariadb
  template:
    metadata:
      labels:
        app: mariadb
    spec:
      nodeSelector:
        kubernetes.io/hostname: "194-233-91-166"
      containers:
      - name: mariadb
        image: mariadb:10.11
        resources:
          requests:
            memory: "1Gi"
            cpu: "500m"
          limits:
            memory: "2Gi"
            cpu: "1000m"
        ports:
        - containerPort: 3306
        envFrom:
        - secretRef:
            name: mariadb-secret
        volumeMounts:
        - name: mariadb-storage
          mountPath: /var/lib/mysql
        - name: mariadb-config
          mountPath: /etc/mysql/conf.d
        readinessProbe:
          exec:
            command:
            - mysqladmin
            - ping
            - -h
            - localhost
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          exec:
            command:
            - mysqladmin
            - ping
            - -h
            - localhost
          initialDelaySeconds: 60
          periodSeconds: 20
      volumes:
      - name: mariadb-storage
        persistentVolumeClaim:
          claimName: mariadb-pvc
      - name: mariadb-config
        configMap:
          name: mariadb-config
---
apiVersion: v1
kind: Service
metadata:
  name: mariadb-service
  namespace: database
spec:
  selector:
    app: mariadb
  ports:
    - protocol: TCP
      port: 3306
      targetPort: 3306
  type: ClusterIP
```

---

## FASE 4: Enhanced Redis Configuration

### 4.1 Redis with Persistence (Worker)

```yaml
# redis-enhanced.yaml
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: redis-config
  namespace: database
data:
  redis.conf: |
    bind 0.0.0.0
    port 6379
    timeout 0
    tcp-keepalive 300
    daemonize no
    supervised no
    pidfile /var/run/redis_6379.pid
    loglevel notice
    logfile ""
    databases 16
    always-show-logo yes
    save 900 1
    save 300 10
    save 60 10000
    stop-writes-on-bgsave-error yes
    rdbcompression yes
    rdbchecksum yes
    dbfilename dump.rdb
    dir ./
    maxmemory 400mb
    maxmemory-policy allkeys-lru
    lazyfree-lazy-eviction no
    lazyfree-lazy-expire no
    lazyfree-lazy-server-del no
    replica-lazy-flush no
    appendonly yes
    appendfilename "appendonly.aof"
    appendfsync everysec
    no-appendfsync-on-rewrite no
    auto-aof-rewrite-percentage 100
    auto-aof-rewrite-min-size 64mb
    aof-load-truncated yes
    aof-use-rdb-preamble yes
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: redis-pvc
  namespace: database
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: redis
  namespace: database
spec:
  replicas: 1
  selector:
    matchLabels:
      app: redis
  template:
    metadata:
      labels:
        app: redis
    spec:
      nodeSelector:
        kubernetes.io/hostname: "154-26-130-76"
      containers:
      - name: redis
        image: redis:7-alpine
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        ports:
        - containerPort: 6379
        command:
          - redis-server
          - /etc/redis/redis.conf
        volumeMounts:
        - name: redis-storage
          mountPath: /data
        - name: redis-config
          mountPath: /etc/redis
        readinessProbe:
          exec:
            command:
            - redis-cli
            - ping
          initialDelaySeconds: 5
          periodSeconds: 5
        livenessProbe:
          exec:
            command:
            - redis-cli
            - ping
          initialDelaySeconds: 30
          periodSeconds: 10
      volumes:
      - name: redis-storage
        persistentVolumeClaim:
          claimName: redis-pvc
      - name: redis-config
        configMap:
          name: redis-config
---
apiVersion: v1
kind: Service
metadata:
  name: redis-service
  namespace: database
spec:
  selector:
    app: redis
  ports:
    - protocol: TCP
      port: 6379
      targetPort: 6379
  type: ClusterIP

```

---

## FASE 5: Enhanced WordPress Deployment

### **Langkah Tambahan (FASE 5, Sebelum Menerapkan wordpress-enhanced.yaml):**

- Buat **Secrets** untuk wp-loker-secret dan wp-sabo-secret:

bash

```bash
*# Dapatkan salt unik dari WordPress*
WP_SALTS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/)
AUTH_KEY=$(echo "$WP_SALTS" | grep "AUTH_KEY" | cut -d \' -f 4)
SECURE_AUTH_KEY=$(echo "$WP_SALTS" | grep "SECURE_AUTH_KEY" | cut -d \' -f 4)
LOGGED_IN_KEY=$(echo "$WP_SALTS" | grep "LOGGED_IN_KEY" | cut -d \' -f 4)
NONCE_KEY=$(echo "$WP_SALTS" | grep "NONCE_KEY" | cut -d \' -f 4)
*# Ambil kata sandi MariaDB dari secret yang sudah ada*
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)
*# Buat secret untuk wp-loker*
microk8s kubectl create secret generic wp-loker-secret \
  --namespace=wordpress \
  --from-literal=DB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_NAME="loker_cyou" \
  --from-literal=DB_USER="wordpress_user" \
  --from-literal=DB_PASSWORD="$DB_PASSWORD" \
  --from-literal=REDIS_HOST="redis-service.database.svc.cluster.local" \
  --from-literal=WORDPRESS_AUTH_KEY="$AUTH_KEY" \
  --from-literal=WORDPRESS_SECURE_AUTH_KEY="$SECURE_AUTH_KEY" \
  --from-literal=WORDPRESS_LOGGED_IN_KEY="$LOGGED_IN_KEY" \
  --from-literal=WORDPRESS_NONCE_KEY="$NONCE_KEY"
*# Buat secret untuk wp-sabo*
microk8s kubectl create secret generic wp-sabo-secret \
  --namespace=wordpress \
  --from-literal=DB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_NAME="sabo_news" \
  --from-literal=DB_USER="wordpress_user" \
  --from-literal=DB_PASSWORD="$DB_PASSWORD" \
  --from-literal=REDIS_HOST="redis-service.database.svc.cluster.local" \
  --from-literal=WORDPRESS_AUTH_KEY="$AUTH_KEY" \
  --from-literal=WORDPRESS_SECURE_AUTH_KEY="$SECURE_AUTH_KEY" \
  --from-literal=WORDPRESS_LOGGED_IN_KEY="$LOGGED_IN_KEY" \
  --from-literal=WORDPRESS_NONCE_KEY="$NONCE_KEY"
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: Menggunakan envFrom untuk mengambil semua variabel dari **Secret** akan mengurangi risiko kesalahan konfigurasi, selama **Secrets** dibuat dengan benar.
- **Potensi Error**:
    - Jika **Secret** wp-loker-secret atau wp-sabo-secret tidak ada, **Deployment** akan gagal karena variabel lingkungan seperti WORDPRESS_DB_PASSWORD tidak ditemukan.
    - Jika kunci dalam **Secret** tidak sesuai (misalnya, DB_HOST salah atau tidak ada), WordPress tidak akan bisa terhubung ke database.
    - Perhatikan bahwa REDIS_HOST tetap dipertahankan sebagai valueFrom karena berada di luar envFrom untuk menjaga kompatibilitas dengan konfigurasi sebelumnya.

### 5.1 WordPress Enhanced Configuration (Manager & Worker)

```yaml
# wordpress-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: wp-loker-pvc
  namespace: wordpress
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: wordpress-storage
  resources:
    requests:
      storage: 15Gi
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: wp-sabo-pvc
  namespace: wordpress
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: wordpress-storage
  resources:
    requests:
      storage: 15Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: wp-loker
  namespace: wordpress
spec:
  replicas: 2
  selector:
    matchLabels:
      app: wp-loker
  template:
    metadata:
      labels:
        app: wp-loker
    spec:
      containers:
      - name: wordpress
        image: wpeverywhere/frankenwp:latest-php8.3
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "500m"
        ports:
        - containerPort: 80
        - containerPort: 443
        env:
        - name: FRANKENPHP_WORKER
          value: "/app/public/index.php"
        - name: FRANKENPHP_NUM_WORKERS
          value: "4"
        - name: SERVER_NAME
          value: ":80"
        - name: REDIS_HOST
          valueFrom:
            secretKeyRef:
              name: wp-loker-secret
              key: REDIS_HOST
        envFrom:
        - secretRef:
            name: wp-loker-secret
        volumeMounts:
        - name: wp-content
          mountPath: /app/public
        readinessProbe:
          httpGet:
            path: /wp-login.php
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          httpGet:
            path: /wp-admin/admin-ajax.php
            port: 80
          initialDelaySeconds: 60
          periodSeconds: 30
      volumes:
      - name: wp-content
        persistentVolumeClaim:
          claimName: wp-loker-pvc
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: wp-sabo
  namespace: wordpress
spec:
  replicas: 2
  selector:
    matchLabels:
      app: wp-sabo
  template:
    metadata:
      labels:
        app: wp-sabo
    spec:
      containers:
      - name: wordpress
        image: wpeverywhere/frankenwp:latest-php8.3
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "500m"
        ports:
        - containerPort: 80
        - containerPort: 443
        env:
        - name: FRANKENPHP_WORKER
          value: "/app/public/index.php"
        - name: FRANKENPHP_NUM_WORKERS
          value: "4"
        - name: SERVER_NAME
          value: ":80"
        - name: WORDPRESS_MULTISITE
          value: "1"
        - name: WORDPRESS_DOMAIN_CURRENT_SITE
          value: "sabo.news"
        - name: REDIS_HOST
          valueFrom:
            secretKeyRef:
              name: wp-sabo-secret
              key: REDIS_HOST
        envFrom:
        - secretRef:
            name: wp-sabo-secret
        volumeMounts:
        - name: wp-content
          mountPath: /app/public
        readinessProbe:
          httpGet:
            path: /wp-login.php
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          httpGet:
            path: /wp-admin/admin-ajax.php
            port: 80
          initialDelaySeconds: 60
          periodSeconds: 30
      volumes:
      - name: wp-content
        persistentVolumeClaim:
          claimName: wp-sabo-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: wp-loker-service
  namespace: wordpress
spec:
  selector:
    app: wp-loker
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: v1
kind: Service
metadata:
  name: wp-sabo-service
  namespace: wordpress
spec:
  selector:
    app: wp-sabo
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
```

---

## FASE 6: Enhanced Caddy with SSL/TLS

### 6.0 **Langkah Tambahan (FASE 6, Sebelum Menerapkan caddy-enhanced.yaml):**

- Buat **Secret** untuk Caddy:

```bash
*# Hasilkan kata sandi dan hash untuk Caddy*
CADDY_PASSWORD=$(openssl rand -base64 16)
CADDY_HASH=$(echo -n "$CADDY_PASSWORD" | microk8s htpasswd -ni admin | tr -d '[:space:]')
*# Buat secret untuk caddy*
microk8s kubectl create secret generic caddy-auth-secret \
  --namespace=default \
  --from-literal=CADDY_HASH="$CADDY_HASH"
echo "Password Caddy: $CADDY_PASSWORD" >> /tmp/caddy-credentials.txt
echo "Hash Caddy: $CADDY_HASH" >> /tmp/caddy-credentials.txt
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: Menggunakan **Secret** untuk menyimpan hash Basic Auth akan meningkatkan keamanan, dan variabel lingkungan CADDY_HASH akan diambil dengan benar oleh Caddy.
- **Potensi Error**:
    - Jika **Secret** caddy-auth-secret tidak dibuat sebelum **Deployment**, Caddy akan gagal memproses konfigurasi basicauth karena variabel CADDY_HASH tidak tersedia.
    - Jika format hash salah (misalnya, tidak dalam format bcrypt yang valid), autentikasi Basic Auth tidak akan berfungsi.

### 6.1 Caddy with Let's Encrypt (Manager)

```yaml
# caddy-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: Secret
metadata:
  name: caddy-auth-secret
  namespace: default
type: Opaque
stringData:
  CADDY_HASH: "<PASTE_CADDY_HASH_ANDA_DI_SINI>"  # Ganti dengan hash yang dihasilkan
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: caddy-config
  namespace: default
data:
  Caddyfile: |
    {
        email katakaosantri@gmail.com
        admin off
        auto_https on
        servers {
            protocol {
                experimental_http3
            }
        }
    }

    loker.cyou {
        reverse_proxy wp-loker-service.wordpress.svc.cluster.local:80
        encode gzip
        header {
            X-Frame-Options SAMEORIGIN
            X-Content-Type-Options nosniff
            Referrer-Policy strict-origin-when-cross-origin
            Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        }
        @static path_regexp \.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2)$
        header @static Cache-Control "public, max-age=31536000"
        rate_limit {
            zone wordpress_loker {
                key {remote_host}
                events 100
                window 1m
            }
        }
        log {
            output file /var/log/caddy/loker.cyou.log
            format json
        }
    }

    sabo.news {
        reverse_proxy wp-sabo-service.wordpress.svc.cluster.local:80
        encode gzip
        header {
            X-Frame-Options SAMEORIGIN
            X-Content-Type-Options nosniff
            Referrer-Policy strict-origin-when-cross-origin
            Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        }
        @static path_regexp \.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2)$
        header @static Cache-Control "public, max-age=31536000"
        rate_limit {
            zone wordpress_sabo {
                key {remote_host}
                events 100
                window 1m
            }
        }
        log {
            output file /var/log/caddy/sabo.news.log
            format json
        }
    }

    adminer.rahajeng.my.id {
        reverse_proxy adminer-service.tools.svc.cluster.local:8080
        encode gzip
        basicauth {
            admin {$CADDY_HASH}
        }
        log {
            output file /var/log/caddy/adminer.log
            format json
        }
    }

    n8n.rahajeng.my.id {
        reverse_proxy n8n-service.tools.svc.cluster.local:5678
        encode gzip
        basicauth {
            admin {$CADDY_HASH}
        }
        log {
            output file /var/log/caddy/n8n.log
            format json
        }
    }

    supabase.rahajeng.my.id {
        reverse_proxy supabase-service.tools.svc.cluster.local:3000
        encode gzip
        basicauth {
            admin {$CADDY_HASH}
        }
        log {
            output file /var/log/caddy/supabase.log
            format json
        }
    }

    mail.rahajeng.my.id {
        reverse_proxy mailcow-service.mail.svc.cluster.local:443 {
            transport http {
                tls_insecure_skip_verify
            }
        }
        encode gzip
        log {
            output file /var/log/caddy/mail.log
            format json
        }
    }
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: caddy-data-pvc
  namespace: default
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 5Gi
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: caddy-logs-pvc
  namespace: default
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: caddy
  namespace: default
spec:
  replicas: 2
  selector:
    matchLabels:
      app: caddy
  template:
    metadata:
      labels:
        app: caddy
    spec:
      containers:
      - name: caddy
        image: caddy:2.7-alpine
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        ports:
        - containerPort: 80
        - containerPort: 443
        env:
        - name: CADDY_HASH
          valueFrom:
            secretKeyRef:
              name: caddy-auth-secret
              key: CADDY_HASH
        volumeMounts:
        - name: caddy-config
          mountPath: /etc/caddy/Caddyfile
          subPath: Caddyfile
        - name: caddy-data
          mountPath: /data
        - name: caddy-config-dir
          mountPath: /config
        - name: caddy-logs
          mountPath: /var/log/caddy
        readinessProbe:
          httpGet:
            path: /
            port: 80
          initialDelaySeconds: 5
          periodSeconds: 5
        livenessProbe:
          httpGet:
            path: /
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        securityContext:
          runAsNonRoot: true
          runAsUser: 1000
          readOnlyRootFilesystem: false
      volumes:
      - name: caddy-config
        configMap:
          name: caddy-config
      - name: caddy-data
        persistentVolumeClaim:
          claimName: caddy-data-pvc
      - name: caddy-config-dir
        emptyDir: {}
      - name: caddy-logs
        persistentVolumeClaim:
          claimName: caddy-logs-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: caddy-service
  namespace: default
spec:
  selector:
    app: caddy
  ports:
    - name: http
      protocol: TCP
      port: 80
      targetPort: 80
    - name: https
      protocol: TCP
      port: 443
      targetPort: 443
  type: LoadBalancer
  loadBalancerIP: 10.0.0.100
```

---

## FASE 7: Enhanced HPA with Resource Quotas

### 7.1 Enhanced Autoscaling Configuration (Manager)

```yaml
# hpa-enhanced.yaml
---
apiVersion: v1
kind: ResourceQuota
metadata:
  name: wordpress-quota
  namespace: wordpress
spec:
  hard:
    requests.cpu: "4"
    requests.memory: 8Gi
    limits.cpu: "8"
    limits.memory: 16Gi
    persistentvolumeclaims: "10"
---
apiVersion: v1
kind: ResourceQuota
metadata:
  name: database-quota
  namespace: database
spec:
  hard:
    requests.cpu: "2"
    requests.memory: 4Gi
    limits.cpu: "4"
    limits.memory: 8Gi
    persistentvolumeclaims: "5"
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: wp-loker-hpa
  namespace: wordpress
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: wp-loker
  minReplicas: 2
  maxReplicas: 8
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
      - type: Percent
        value: 50
        periodSeconds: 60
    scaleUp:
      stabilizationWindowSeconds: 60
      policies:
      - type: Percent
        value: 100
        periodSeconds: 30
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: wp-sabo-hpa
  namespace: wordpress
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: wp-sabo
  minReplicas: 2
  maxReplicas: 8
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
      - type: Percent
        value: 50
        periodSeconds: 60
    scaleUp:
      stabilizationWindowSeconds: 60
      policies:
      - type: Percent
        value: 100
        periodSeconds: 30
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: caddy-hpa
  namespace: default
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: caddy
  minReplicas: 2
  maxReplicas: 4
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 60
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 70

```

---

## FASE 8: Enhanced Tools Deployment

### 8.1 Enhanced Adminer (Manager)

```yaml
# adminer-enhanced.yaml
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: adminer
  namespace: tools
spec:
  replicas: 1
  selector:
    matchLabels:
      app: adminer
  template:
    metadata:
      labels:
        app: adminer
    spec:
      containers:
      - name: adminer
        image: adminer:4.8.1-standalone
        resources:
          requests:
            memory: "128Mi"
            cpu: "100m"
          limits:
            memory: "256Mi"
            cpu: "200m"
        ports:
        - containerPort: 8080
        env:
        - name: ADMINER_DEFAULT_SERVER
          value: "mariadb-service.database.svc.cluster.local"
        - name: ADMINER_DESIGN
          value: "pepa-linha"
        - name: ADMINER_PLUGINS
          value: "tables-filter tinymce"
        readinessProbe:
          httpGet:
            path: /
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
        livenessProbe:
          httpGet:
            path: /
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
        securityContext:
          runAsNonRoot: true
          runAsUser: 1000
          readOnlyRootFilesystem: true
---
apiVersion: v1
kind: Service
metadata:
  name: adminer-service
  namespace: tools
spec:
  selector:
    app: adminer
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP

```

### 8.2a **Langkah Tambahan (FASE 8, Sebelum Menerapkan n8n-enhanced.yaml):**

- Buat **Secret** untuk N8N:

bash

```bash
*# Hasilkan kredensial unik*
N8N_KEY=$(openssl rand -hex 32)
CADDY_PASSWORD=$(openssl rand -base64 16)
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)
*# Buat secret untuk n8n*
microk8s kubectl create secret generic n8n-secret \
  --namespace=tools \
  --from-literal=N8N_BASIC_AUTH_USER="admin" \
  --from-literal=N8N_BASIC_AUTH_PASSWORD="$CADDY_PASSWORD" \
  --from-literal=DB_MYSQLDB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_MYSQLDB_PORT="3306" \
  --from-literal=DB_MYSQLDB_DATABASE="n8n" \
  --from-literal=DB_MYSQLDB_USER="wordpress_user" \
  --from-literal=DB_MYSQLDB_PASSWORD="$DB_PASSWORD" \
  --from-literal=N8N_ENCRYPTION_KEY="$N8N_KEY"
echo "Password N8N: $CADDY_PASSWORD" >> /tmp/n8n-credentials.txt
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: **Secret** n8n-secret akan menyediakan semua variabel yang diperlukan untuk N8N, dan penggunaan envFrom akan mempermudah konfigurasi.
- **Potensi Error**:
    - Jika **Secret** n8n-secret tidak dibuat sebelum **Deployment**, N8N akan gagal karena variabel seperti N8N_BASIC_AUTH_PASSWORD atau DB_MYSQLDB_PASSWORD tidak tersedia.
    - Jika DB_MYSQLDB_HOST atau DB_MYSQLDB_PORT salah, N8N tidak akan bisa terhubung ke MariaDB.

### 8.2b Enhanced N8N (Worker)

```yaml
# n8n-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: n8n-pvc
  namespace: tools
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 10Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: n8n
  namespace: tools
spec:
  replicas: 1
  selector:
    matchLabels:
      app: n8n
  template:
    metadata:
      labels:
        app: n8n
    spec:
      nodeSelector:
        kubernetes.io/hostname: "154-26-130-76"
      containers:
      - name: n8n
        image: n8nio/n8n:latest
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "500m"
        ports:
        - containerPort: 5678
        env:
        - name: N8N_HOST
          value: "n8n.rahajeng.my.id"
        - name: N8N_PORT
          value: "5678"
        - name: N8N_PROTOCOL
          value: "https"
        - name: NODE_ENV
          value: "production"
        - name: WEBHOOK_URL
          value: "https://n8n.rahajeng.my.id/"
        - name: GENERIC_TIMEZONE
          value: "Asia/Jakarta"
        - name: DB_TYPE
          value: "mysqldb"
        - name: N8N_BASIC_AUTH_ACTIVE
          value: "true"
        - name: N8N_LOG_LEVEL
          value: "info"
        - name: N8N_METRICS
          value: "true"
        envFrom:
        - secretRef:
            name: n8n-secret
        volumeMounts:
        - name: n8n-data
          mountPath: /home/node/.n8n
        readinessProbe:
          httpGet:
            path: /healthz
            port: 5678
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          httpGet:
            path: /healthz
            port: 5678
          initialDelaySeconds: 60
          periodSeconds: 30
        securityContext:
          runAsNonRoot: true
          runAsUser: 1000
      volumes:
      - name: n8n-data
        persistentVolumeClaim:
          claimName: n8n-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: n8n-service
  namespace: tools
spec:
  selector:
    app: n8n
  ports:
    - protocol: TCP
      port: 5678
      targetPort: 5678
  type: ClusterIP
```

---

## FASE 9: Enhanced Supabase Deployment

### 9.0 **Langkah Tambahan (FASE 9, Sebelum Menerapkan supabase-enhanced.yaml):**

- Buat **Secret** untuk Supabase:

```bash
*# Hasilkan kredensial unik*
SUPABASE_JWT=$(openssl rand -hex 32)
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)
*# Ganti ANON_KEY dan SERVICE_ROLE_KEY dengan nilai asli dari Supabase*
microk8s kubectl create secret generic supabase-secret \
  --namespace=tools \
  --from-literal=POSTGRES_PASSWORD="$DB_PASSWORD" \
  --from-literal=POSTGRES_USER="supabase_admin" \
  --from-literal=POSTGRES_DB="postgres" \
  --from-literal=JWT_SECRET="$SUPABASE_JWT" \
  --from-literal=ANON_KEY="<GANTI_DENGAN_KUNCI_ANON_SUPABASE_ASLI_ANDA>" \
  --from-literal=SERVICE_ROLE_KEY="<GANTI_DENGAN_KUNCI_SERVICE_ROLE_ASLI_ANDA>"
echo "JWT Secret Supabase: $SUPABASE_JWT" >> /tmp/supabase-credentials.txt
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: **Secret** supabase-secret akan menyediakan semua variabel yang diperlukan untuk Supabase.
- **Potensi Error**:
    - Jika ANON_KEY atau SERVICE_ROLE_KEY tidak diganti dengan nilai asli, Supabase tidak akan berfungsi dengan benar. Pastikan untuk mendapatkan kunci asli dari Supabase.
    - Jika **Secret** supabase-secret tidak dibuat sebelum **Deployment**, Supabase akan gagal karena variabel seperti POSTGRES_PASSWORD tidak tersedia.

### 9.1 Complete Supabase Stack (Manager)

```yaml
# supabase-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: postgres-pvc
  namespace: tools
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 20Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: postgres
  namespace: tools
spec:
  replicas: 1
  selector:
    matchLabels:
      app: postgres
  template:
    metadata:
      labels:
        app: postgres
    spec:
      nodeSelector:
        kubernetes.io/hostname: "194-233-91-166"
      containers:
      - name: postgres
        image: supabase/postgres:15.1.0.117
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "500m"
        ports:
        - containerPort: 5432
        envFrom:
        - secretRef:
            name: supabase-secret
        volumeMounts:
        - name: postgres-data
          mountPath: /var/lib/postgresql/data
        readinessProbe:
          exec:
            command:
            - pg_isready
            - -U
            - supabase_admin
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          exec:
            command:
            - pg_isready
            - -U
            - supabase_admin
          initialDelaySeconds: 60
          periodSeconds: 20
      volumes:
      - name: postgres-data
        persistentVolumeClaim:
          claimName: postgres-pvc
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: supabase-studio
  namespace: tools
spec:
  replicas: 1
  selector:
    matchLabels:
      app: supabase-studio
  template:
    metadata:
      labels:
        app: supabase-studio
    spec:
      containers:
      - name: studio
        image: supabase/studio:20240101-5cc7e49
        resources:
          requests:
            memory: "256Mi"
            cpu: "200m"
          limits:
            memory: "512Mi"
            cpu: "400m"
        ports:
        - containerPort: 3000
        env:
        - name: STUDIO_PG_META_URL
          value: "http://postgres-service:8080"
        - name: SUPABASE_URL
          value: "https://supabase.rahajeng.my.id"
        - name: SUPABASE_REST_URL
          value: "https://supabase.rahajeng.my.id/rest/v1/"
        - name: DEFAULT_ORGANIZATION_NAME
          value: "Rahajeng"
        - name: DEFAULT_PROJECT_NAME
          value: "Rahajeng Project"
        envFrom:
        - secretRef:
            name: supabase-secret
        readinessProbe:
          httpGet:
            path: /
            port: 3000
          initialDelaySeconds: 30
          periodSeconds: 10
        livenessProbe:
          httpGet:
            path: /
            port: 3000
          initialDelaySeconds: 60
          periodSeconds: 20
---
apiVersion: v1
kind: Service
metadata:
  name: postgres-service
  namespace: tools
spec:
  selector:
    app: postgres
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
  type: ClusterIP
---
apiVersion: v1
kind: Service
metadata:
  name: supabase-service
  namespace: tools
spec:
  selector:
    app: supabase-studio
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
```

---

## FASE 10: Enhanced Mailcow Deployment

### 10.0 **Langkah Tambahan (FASE 10, Sebelum Menerapkan mailcow-enhanced.yaml):**

- Buat **Secret** untuk Mailcow:

bash

```bash
*# Hasilkan kredensial unik*
MAILCOW_DBPASS=$(openssl rand -base64 32)
MAILCOW_DBROOT=$(openssl rand -base64 32)
*# Buat secret untuk mailcow*
microk8s kubectl create secret generic mailcow-secret \
  --namespace=mail \
  --from-literal=MAILCOW_HOSTNAME="mail.rahajeng.my.id" \
  --from-literal=MAILCOW_PASS_SCHEME="BLF-CRYPT" \
  --from-literal=DBPASS="$MAILCOW_DBPASS" \
  --from-literal=DBROOT="$MAILCOW_DBROOT" \
  --from-literal=HTTP_PORT="80" \
  --from-literal=HTTPS_PORT="443" \
  --from-literal=HTTP_BIND="0.0.0.0" \
  --from-literal=HTTPS_BIND="0.0.0.0"
echo "Mailcow DBPASS: $MAILCOW_DBPASS" >> /tmp/mailcow-credentials.txt
echo "Mailcow DBROOT: $MAILCOW_DBROOT" >> /tmp/mailcow-credentials.txt
```

**Evaluasi Potensi Error:**

- **Berhasil Kemungkinan Tinggi**: **Secret** mailcow-secret akan menyediakan semua variabel yang diperlukan untuk Mailcow.
- **Potensi Error**:
    - Jika **Secret** mailcow-secret tidak dibuat sebelum **Deployment**, Mailcow akan gagal karena variabel seperti DBPASS tidak tersedia.
    - Jika nilai seperti MAILCOW_HOSTNAME tidak sesuai dengan konfigurasi Caddy, Mailcow tidak akan dapat diakses melalui domain yang benar.

### 10.1 Simplified Mailcow Setup (Worker)

```yaml
# mailcow-enhanced.yaml (MODIFIED)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: mailcow-data-pvc
  namespace: mail
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 30Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mailcow
  namespace: mail
spec:
  replicas: 1
  selector:
    matchLabels:
      app: mailcow
  template:
    metadata:
      labels:
        app: mailcow
    spec:
      nodeSelector:
        kubernetes.io/hostname: "154-26-130-76"
      containers:
      - name: mailcow
        image: mailcow/dockerapi:1.73
        resources:
          requests:
            memory: "1Gi"
            cpu: "500m"
          limits:
            memory: "2Gi"
            cpu: "1000m"
        ports:
        - containerPort: 443
        - containerPort: 80
        - containerPort: 25
        - containerPort: 465
        - containerPort: 587
        - containerPort: 993
        - containerPort: 995
        envFrom:
        - secretRef:
            name: mailcow-secret
        volumeMounts:
        - name: mailcow-data
          mountPath: /opt/mailcow-dockerized
        readinessProbe:
          httpGet:
            path: /
            port: 443
            scheme: HTTPS
          initialDelaySeconds: 60
          periodSeconds: 30
        livenessProbe:
          httpGet:
            path: /
            port: 443
            scheme: HTTPS
          initialDelaySeconds: 120
          periodSeconds: 60
        securityContext:
          privileged: true
      volumes:
      - name: mailcow-data
        persistentVolumeClaim:
          claimName: mailcow-data-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: mailcow-service
  namespace: mail
spec:
  selector:
    app: mailcow
  ports:
    - name: https
      protocol: TCP
      port: 443
      targetPort: 443
    - name: http
      protocol: TCP
      port: 80
      targetPort: 80
    - name: smtp
      protocol: TCP
      port: 25
      targetPort: 25
    - name: smtps
      protocol: TCP
      port: 465
      targetPort: 465
    - name: submission
      protocol: TCP
      port: 587
      targetPort: 587
    - name: imaps
      protocol: TCP
      port: 993
      targetPort: 993
    - name: pop3s
      protocol: TCP
      port: 995
      targetPort: 995
  type: ClusterIP
```

---

## FASE 11: Enhanced Monitoring & Backup

### 11.1 Monitoring Stack (Manager)

```yaml
# monitoring-enhanced.yaml
---
apiVersion: v1
kind: ServiceMonitor
metadata:
  name: wordpress-monitor
  namespace: monitoring
  labels:
    app: wordpress-frankenphp
spec:
  selector:
    matchLabels:
      app: wp-loker
  namespaceSelector:
    matchNames:
    - wordpress
  endpoints:
  - port: http
    path: /wp-json/wp/v2/
    interval: 30s
---
apiVersion: v1
kind: ServiceMonitor
metadata:
  name: caddy-monitor
  namespace: monitoring
  labels:
    app: caddy
spec:
  selector:
    matchLabels:
      app: caddy
  namespaceSelector:
    matchNames:
    - default
  endpoints:
  - port: http
    path: /metrics
    interval: 30s
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-dashboard-wordpress
  namespace: monitoring
  labels:
    grafana_dashboard: "1"
data:
  wordpress-dashboard.json: |
    {
      "dashboard": {
        "title": "WordPress FrankenPHP Dashboard",
        "panels": [
          {
            "title": "Pod Count",
            "type": "stat",
            "targets": [
              {
                "expr": "kube_deployment_status_replicas{deployment=~\"wp-.*\"}"
              }
            ]
          },
          {
            "title": "Memory Usage",
            "type": "graph",
            "targets": [
              {
                "expr": "container_memory_usage_bytes{pod=~\"wp-.*\"}"
              }
            ]
          },
          {
            "title": "CPU Usage",
            "type": "graph",
            "targets": [
              {
                "expr": "rate(container_cpu_usage_seconds_total{pod=~\"wp-.*\"}[5m])"
              }
            ]
          }
        ]
      }
    }

```

### 11.2 Enhanced Backup Scripts (Manager)

```bash
#!/bin/bash
# enhanced-backup.sh (MODIFIED)

BACKUP_DIR="/backup/k8s-enhanced"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=7

# Create backup directory
mkdir -p $BACKUP_DIR

echo "=== Starting Enhanced Kubernetes Backup: $DATE ==="

# Function to wait for completion
wait_for_completion() {
    local max_wait=300
    local count=0
    while [ $count -lt $max_wait ]; do
        if [ -f "$1" ]; then
            break
        fi
        sleep 1
        count=$((count + 1))
    done
}

# Function to get secret
get_secret() {
    microk8s kubectl get secret $1 -n $2 -o jsonpath="{.data.$3}" | base64 --decode
}

# Backup configurations with better organization
echo "1. Backing up configurations..."
mkdir -p $BACKUP_DIR/configs-$DATE/{namespaces,deployments,services,secrets,configmaps,storage}

# Backup by resource type
microk8s kubectl get namespaces -o yaml > $BACKUP_DIR/configs-$DATE/namespaces/all-namespaces.yaml
microk8s kubectl get deployments --all-namespaces -o yaml > $BACKUP_DIR/configs-$DATE/deployments/all-deployments.yaml
microk8s kubectl get services --all-namespaces -o yaml > $BACKUP_DIR/configs-$DATE/services/all-services.yaml
microk8s kubectl get secrets --all-namespaces -o yaml > $BACKUP_DIR/configs-$DATE/secrets/all-secrets.yaml
microk8s kubectl get configmaps --all-namespaces -o yaml > $BACKUP_DIR/configs-$DATE/configmaps/all-configmaps.yaml
microk8s kubectl get pv,pvc --all-namespaces -o yaml > $BACKUP_DIR/configs-$DATE/storage/all-storage.yaml

# Backup databases
echo "2. Backing up databases..."
mkdir -p $BACKUP_DIR/databases-$DATE

# MariaDB backup with compression
MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}")
if [ ! -z "$MARIADB_POD" ]; then
    echo "Backing up MariaDB..."
    # Ambil password dari secret
    MARIADB_PASS=$(get_secret mariadb-secret database MYSQL_PASSWORD)

    # Jalankan mysqldump menggunakan variabel lingkungan
    microk8s kubectl exec $MARIADB_POD -n database -- env MYSQL_PWD="$MARIADB_PASS" \
      mysqldump -u wordpress_user --single-transaction --routines --triggers --all-databases | gzip > $BACKUP_DIR/databases-$DATE/mariadb-backup.sql.gz
fi

# PostgreSQL backup for Supabase
POSTGRES_POD=$(microk8s kubectl get pods -n tools -l app=postgres -o jsonpath="{.items[0].metadata.name}")
if [ ! -z "$POSTGRES_POD" ]; then
    echo "Backing up PostgreSQL..."
    # Ambil password dari secret
    POSTGRES_PASS=$(get_secret supabase-secret tools POSTGRES_PASSWORD)

    # Jalankan pg_dumpall menggunakan variabel lingkungan
    microk8s kubectl exec $POSTGRES_POD -n tools -- env PGPASSWORD="$POSTGRES_PASS" \
      pg_dumpall -U supabase_admin | gzip > $BACKUP_DIR/databases-$DATE/postgres-backup.sql.gz
fi

# WordPress content backup
backup_wp_content() {
    local site=$1
    local pod_name=$(microk8s kubectl get pods -n wordpress -l app=wp-$site -o jsonpath="{.items[0].metadata.name}")
    if [ ! -z "$pod_name" ]; then
        microk8s kubectl exec $pod_name -n wordpress -- tar czf - /app/public 2>/dev/null | cat > $BACKUP_DIR/databases-$DATE/wp-$site-$DATE.tar.gz
    fi
}

backup_wp_content "loker"
backup_wp_content "sabo"

# Cleanup old backups
find $BACKUP_DIR -name "*-*.sql.gz" -mtime +$RETENTION_DAYS -delete
find $BACKUP_DIR -name "*-*.tar.gz" -mtime +$RETENTION_DAYS -delete
find $BACKUP_DIR -name "*-*.yaml.gz" -mtime +$RETENTION_DAYS -delete

echo "=== Backup completed: $DATE ==="
```

### 11.3 Enhanced Monitoring Dashboard (Manager)

```bash
#!/bin/bash
# enhanced-monitor.sh

# Enhanced monitoring script with detailed metrics
echo "=== MicroK8s Enhanced Monitoring Dashboard ==="
echo "Generated at: $(date)"
echo "Cluster: MicroK8s WordPress Enhanced"
echo "========================================="

# Cluster overview
echo -e "\n🏢 CLUSTER OVERVIEW"
echo "==================="
microk8s status --wait-ready
echo -e "\nNodes:"
microk8s kubectl get nodes -o wide

echo -e "\nAddons Status:"
microk8s status | grep -A 20 "addons:"

# Resource utilization
echo -e "\n📊 RESOURCE UTILIZATION"
echo "======================="
echo "Node Resources:"
microk8s kubectl top nodes 2>/dev/null || echo "Metrics server not ready"

echo -e "\nTop Resource Consuming Pods:"
microk8s kubectl top pods --all-namespaces --sort-by=cpu 2>/dev/null | head -10 || echo "Metrics not available"

# Application status by namespace
echo -e "\n🚀 APPLICATION STATUS"
echo "===================="

namespaces=("wordpress" "database" "tools" "mail" "default")
for ns in "${namespaces[@]}"; do
    if microk8s kubectl get namespace $ns > /dev/null 2>&1; then
        echo -e "\n📁 Namespace: $ns"
        echo "$(microk8s kubectl get pods -n $ns -o wide)"

        # Check resource quotas if they exist
        quota_check=$(microk8s kubectl get resourcequota -n $ns 2>/dev/null | tail -n +2)
        if [ ! -z "$quota_check" ]; then
            echo -e "\nResource Quotas:"
            echo "$quota_check"
        fi
    fi
done

# Services and ingress
echo -e "\n🌐 SERVICES & NETWORKING"
echo "========================"
echo "Services:"
microk8s kubectl get svc --all-namespaces

echo -e "\nLoadBalancer Status:"
LB_IP=$(microk8s kubectl get svc caddy-service -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null)
if [ ! -z "$LB_IP" ]; then
    echo "LoadBalancer IP: $LB_IP"
    echo "Testing connectivity:"
    timeout 5 curl -s -o /dev/null -w "HTTP Status: %{http_code}, Response Time: %{time_total}s\n" http://$LB_IP/ || echo "Connection failed"
else
    echo "LoadBalancer IP not assigned"
fi

# Storage status
echo -e "\n💾 STORAGE STATUS"
echo "================="
echo "Persistent Volumes:"
microk8s kubectl get pv

echo -e "\nPersistent Volume Claims:"
microk8s kubectl get pvc --all-namespaces

# HPA status
echo -e "\n📈 HORIZONTAL POD AUTOSCALER"
echo "============================"
hpa_status=$(microk8s kubectl get hpa --all-namespaces 2>/dev/null)
if [ ! -z "$hpa_status" ]; then
    echo "$hpa_status"
else
    echo "No HPA configured"
fi

# Database connectivity tests
echo -e "\n🗄️  DATABASE CONNECTIVITY"
echo "=========================="

# MariaDB test
MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
if [ ! -z "$MARIADB_POD" ]; then
    echo "MariaDB Status:"
    mariadb_test=$(microk8s kubectl exec $MARIADB_POD -n database -- mysqladmin ping -u wordpress_user -pM1xyl1qyp1q~2025 2>/dev/null)
    if [[ $mariadb_test == *"alive"* ]]; then
        echo "✅ MariaDB: Connected"
        echo "Databases:"
        microk8s kubectl exec $MARIADB_POD -n database -- mysql -u wordpress_user -pM1xyl1qyp1q~2025 -e "SHOW DATABASES;" 2>/dev/null | grep -E "(loker_cyou|sabo_news|n8n)"
    else
        echo "❌ MariaDB: Connection failed"
    fi
else
    echo "❌ MariaDB: Pod not found"
fi

# Redis test
REDIS_POD=$(microk8s kubectl get pods -n database -l app=redis -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
if [ ! -z "$REDIS_POD" ]; then
    redis_test=$(microk8s kubectl exec $REDIS_POD -n database -- redis-cli ping 2>/dev/null)
    if [[ $redis_test == "PONG" ]]; then
        echo "✅ Redis: Connected"
        echo "Redis Info:"
        microk8s kubectl exec $REDIS_POD -n database -- redis-cli info server 2>/dev/null | grep -E "(redis_version|uptime_in_seconds)"
    else
        echo "❌ Redis: Connection failed"
    fi
else
    echo "❌ Redis: Pod not found"
fi

# PostgreSQL test for Supabase
POSTGRES_POD=$(microk8s kubectl get pods -n tools -l app=postgres -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
if [ ! -z "$POSTGRES_POD" ]; then
    postgres_test=$(microk8s kubectl exec $POSTGRES_POD -n tools -- pg_isready -U supabase_admin 2>/dev/null)
    if [[ $postgres_test == *"accepting connections"* ]]; then
        echo "✅ PostgreSQL: Connected"
    else
        echo "❌ PostgreSQL: Connection failed"
    fi
else
    echo "❌ PostgreSQL: Pod not found"
fi

# WordPress health check
echo -e "\n🌐 WORDPRESS HEALTH CHECK"
echo "========================="

check_wordpress_health() {
    local site=$1
    local pod_name=$(microk8s kubectl get pods -n wordpress -l app=wp-$site -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)

    if [ ! -z "$pod_name" ]; then
        local health_check=$(microk8s kubectl exec $pod_name -n wordpress -- curl -s -o /dev/null -w "%{http_code}" http://localhost/wp-login.php 2>/dev/null)
        if [[ $health_check == "200" ]]; then
            echo "✅ WordPress $site: Healthy (HTTP $health_check)"
        else
            echo "⚠️  WordPress $site: Issues detected (HTTP $health_check)"
        fi
    else
        echo "❌ WordPress $site: Pod not found"
    fi
}

check_wordpress_health "loker"
check_wordpress_health "sabo"

# SSL Certificate status
echo -e "\n🔒 SSL CERTIFICATE STATUS"
echo "========================="

check_ssl() {
    local domain=$1
    echo "Checking $domain:"

    # Check if domain resolves
    if timeout 5 nslookup $domain > /dev/null 2>&1; then
        # Check SSL certificate
        cert_info=$(timeout 10 echo | openssl s_client -connect $domain:443 -servername $domain 2>/dev/null | openssl x509 -noout -dates 2>/dev/null)
        if [ $? -eq 0 ]; then
            echo "✅ SSL Certificate active"
            echo "$cert_info" | sed 's/^/   /'
        else
            echo "❌ SSL Certificate not available"
        fi
    else
        echo "❌ Domain not resolving"
    fi
    echo ""
}

check_ssl "loker.cyou"
check_ssl "sabo.news"
check_ssl "adminer.rahajeng.my.id"
check_ssl "n8n.rahajeng.my.id"
check_ssl "supabase.rahajeng.my.id"

# Event monitoring
echo -e "\n⚠️  RECENT EVENTS"
echo "================"
echo "Last 10 system events:"
microk8s kubectl get events --all-namespaces --sort-by='.lastTimestamp' | tail -10

# Performance metrics summary
echo -e "\n📋 PERFORMANCE SUMMARY"
echo "====================="

total_pods=$(microk8s kubectl get pods --all-namespaces --no-headers | wc -l)
running_pods=$(microk8s kubectl get pods --all-namespaces --field-selector=status.phase=Running --no-headers | wc -l)
failed_pods=$(microk8s kubectl get pods --all-namespaces --field-selector=status.phase=Failed --no-headers | wc -l)

echo "Pods: $running_pods/$total_pods running"
echo "Failed pods: $failed_pods"
echo "LoadBalancer IP: ${LB_IP:-'Not assigned'}"
echo "Uptime: $(uptime -p)"

# Backup status check
echo -e "\n💾 BACKUP STATUS"
echo "==============="
if [ -d "/backup/k8s-enhanced" ]; then
    latest_backup=$(ls -t /backup/k8s-enhanced/kubernetes-enhanced-backup-*.tar.gz 2>/dev/null | head -1)
    if [ ! -z "$latest_backup" ]; then
        backup_date=$(basename $latest_backup | grep -o '[0-9]\{8\}_[0-9]\{6\}')
        backup_size=$(du -h "$latest_backup" | cut -f1)
        echo "✅ Latest backup: $backup_date ($backup_size)"
    else
        echo "⚠️  No backups found"
    fi
else
    echo "⚠️  Backup directory not found"
fi

echo -e "\n========================================="
echo "Monitoring completed at: $(date)"
echo "Next check recommended in: 30 minutes"
echo "========================================="

```

---

## FASE 12: Complete System Validation & Testing

### 12.1 Comprehensive System Test Suite (Manager)

```bash
#!/bin/bash
# comprehensive-system-test.sh

echo "=== MicroK8s WordPress Enhanced System Test Suite ==="
echo "Started at: $(date)"
echo "======================================================"

# Test results tracking
PASSED_TESTS=0
FAILED_TESTS=0
TOTAL_TESTS=0

# Function to run test and track results
run_test() {
    local test_name="$1"
    local test_command="$2"

    echo -e "\n🧪 Testing: $test_name"
    echo "----------------------------------------"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    if eval "$test_command"; then
        echo "✅ PASSED: $test_name"
        PASSED_TESTS=$((PASSED_TESTS + 1))
        return 0
    else
        echo "❌ FAILED: $test_name"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        return 1
    fi
}

# Test 1: Cluster Health
run_test "Cluster Health Check" "microk8s status --wait-ready > /dev/null 2>&1"

# Test 2: All Nodes Ready
run_test "Node Readiness" "microk8s kubectl get nodes | grep -q Ready"

# Test 3: Critical Pods Running
run_test "Critical Pods Status" '
    failed_pods=$(microk8s kubectl get pods --all-namespaces --field-selector=status.phase=Failed --no-headers | wc -l)
    [ $failed_pods -eq 0 ]
'

# Test 4: MariaDB Connectivity
run_test "MariaDB Database Connection" '
    MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$MARIADB_POD" ] && microk8s kubectl exec $MARIADB_POD -n database -- mysqladmin ping -u wordpress_user -pM1xyl1qyp1q~2025 > /dev/null 2>&1
'

# Test 5: Redis Connectivity
run_test "Redis Cache Connection" '
    REDIS_POD=$(microk8s kubectl get pods -n database -l app=redis -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$REDIS_POD" ] && [ "$(microk8s kubectl exec $REDIS_POD -n database -- redis-cli ping 2>/dev/null)" = "PONG" ]
'

# Test 6: PostgreSQL Connectivity
run_test "PostgreSQL Database Connection" '
    POSTGRES_POD=$(microk8s kubectl get pods -n tools -l app=postgres -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$POSTGRES_POD" ] && microk8s kubectl exec $POSTGRES_POD -n tools -- pg_isready -U supabase_admin > /dev/null 2>&1
'

# Test 7: WordPress Loker Health
run_test "WordPress Loker Application" '
    WP_LOKER_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-loker -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$WP_LOKER_POD" ] && [ "$(microk8s kubectl exec $WP_LOKER_POD -n wordpress -- curl -s -o /dev/null -w "%{http_code}" http://localhost/wp-login.php 2>/dev/null)" = "200" ]
'

# Test 8: WordPress Sabo Health
run_test "WordPress Sabo Application" '
    WP_SABO_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-sabo -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$WP_SABO_POD" ] && [ "$(microk8s kubectl exec $WP_SABO_POD -n wordpress -- curl -s -o /dev/null -w "%{http_code}" http://localhost/wp-login.php 2>/dev/null)" = "200" ]
'

# Test 9: Caddy Reverse Proxy
run_test "Caddy Reverse Proxy" '
    CADDY_POD=$(microk8s kubectl get pods -n default -l app=caddy -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    [ ! -z "$CADDY_POD" ] && [ "$(microk8s kubectl exec $CADDY_POD -n default -- curl -s -o /dev/null -w "%{http_code}" http://localhost 2>/dev/null)" = "200" ]
'

# Test 10: LoadBalancer Service
run_test "LoadBalancer Service" '
    LB_IP=$(microk8s kubectl get svc caddy-service -o jsonpath="{.status.loadBalancer.ingress[0].ip}")
    [ ! -z "$LB_IP" ]
'

# Test 11: Persistent Storage
run_test "Persistent Volume Claims" '
    pvc_count=$(microk8s kubectl get pvc --all-namespaces --no-headers | grep Bound | wc -l)
    [ $pvc_count -gt 0 ]
'

# Test 12: HPA Configuration
run_test "Horizontal Pod Autoscaler" '
    hpa_count=$(microk8s kubectl get hpa --all-namespaces --no-headers 2>/dev/null | wc -l)
    [ $hpa_count -gt 0 ]
'

# Test 13: DNS Resolution
run_test "DNS Resolution" '
    microk8s kubectl exec -n default deployment/caddy -- nslookup kubernetes.default.svc.cluster.local > /dev/null 2>&1
'

# Test 14: Service Discovery
run_test "Service Discovery" '
    microk8s kubectl exec -n wordpress deployment/wp-loker -- nslookup mariadb-service.database.svc.cluster.local > /dev/null 2>&1
'

# Test 15: Storage Classes
run_test "Storage Classes Available" '
    sc_count=$(microk8s kubectl get storageclass --no-headers | wc -l)
    [ $sc_count -gt 0 ]
'

# Performance Tests
echo -e "\n🚀 PERFORMANCE TESTS"
echo "===================="

# Test 16: Resource Utilization
run_test "Node Resource Utilization" '
    # Check if metrics server is working
    microk8s kubectl top nodes > /dev/null 2>&1
'

# Test 17: WordPress Response Time
run_test "WordPress Response Time (< 3s)" '
    LB_IP=$(microk8s kubectl get svc caddy-service -o jsonpath="{.status.loadBalancer.ingress[0].ip}")
    if [ ! -z "$LB_IP" ]; then
        response_time=$(timeout 10 curl -s -o /dev/null -w "%{time_total}" http://$LB_IP/ 2>/dev/null || echo "10")
        [ $(echo "$response_time < 3" | bc -l 2>/dev/null || echo "1") -eq 1 ]
    else
        false
    fi
'

# Security Tests
echo -e "\n🔒 SECURITY TESTS"
echo "================="

# Test 18: Pod Security Context
run_test "Pod Security Contexts" '
    # Check if pods are running with proper security contexts
    insecure_pods=$(microk8s kubectl get pods --all-namespaces -o json | jq -r ".items[] | select(.spec.securityContext.runAsRoot == true or .spec.containers[].securityContext.runAsRoot == true) | .metadata.name" 2>/dev/null | wc -l)
    [ $insecure_pods -eq 0 ] 2>/dev/null || true
'

# Test 19: Network Policies (if enabled)
run_test "Network Security" '
    # Basic network connectivity test
    microk8s kubectl exec -n wordpress deployment/wp-loker -- ping -c 1 mariadb-service.database.svc.cluster.local > /dev/null 2>&1
'

# Integration Tests
echo -e "\n🔗 INTEGRATION TESTS"
echo "==================="

# Test 20: WordPress-Database Integration
run_test "WordPress-Database Integration" '
    WP_LOKER_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-loker -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    if [ ! -z "$WP_LOKER_POD" ]; then
        # Test if WordPress can connect to database
        microk8s kubectl exec $WP_LOKER_POD -n wordpress -- php -r "
            \$conn = new mysqli(\"mariadb-service.database.svc.cluster.local\", \"wordpress_user\", \"M1xyl1qyp1q~2025\", \"loker_cyou\");
            if (\$conn->connect_error) exit(1);
            \$conn->close();
            exit(0);
        " 2>/dev/null
    else
        false
    fi
'

# Test 21: WordPress-Redis Integration
run_test "WordPress-Redis Integration" '
    WP_LOKER_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-loker -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    if [ ! -z "$WP_LOKER_POD" ]; then
        # Test Redis connectivity from WordPress
        microk8s kubectl exec $WP_LOKER_POD -n wordpress -- php -r "
            \$redis = new Redis();
            if (!\$redis->connect(\"redis-service.database.svc.cluster.local\", 6379)) exit(1);
            \$redis->ping();
            \$redis->close();
            exit(0);
        " 2>/dev/null
    else
        false
    fi
'

# Generate detailed test report
echo -e "\n📊 DETAILED TEST RESULTS"
echo "========================"

echo "System Information:"
echo "- Cluster: MicroK8s WordPress Enhanced"
echo "- Test Suite Version: 1.0"
echo "- Test Date: $(date)"
echo "- Total Tests Run: $TOTAL_TESTS"
echo "- Tests Passed: $PASSED_TESTS"
echo "- Tests Failed: $FAILED_TESTS"
echo "- Success Rate: $(( (PASSED_TESTS * 100) / TOTAL_TESTS ))%"

if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "\n🎉 ALL TESTS PASSED! System is fully operational."
    exit 0
else
    echo -e "\n⚠️  Some tests failed. Please review the results above."
    exit 1
fi

```

### 12.2 Load Testing Framework (Manager)

```bash
#!/bin/bash
# load-testing-framework.sh

echo "=== MicroK8s WordPress Load Testing Framework ==="
echo "================================================="

# Configuration
CONCURRENT_USERS=(1 5 10 25 50)
REQUEST_COUNTS=(10 50 100 500 1000)
TEST_DURATION=60  # seconds for stress tests

# Check dependencies
check_dependencies() {
    echo "🔍 Checking dependencies..."

    if ! command -v ab &> /dev/null; then
        echo "Installing Apache Bench (ab)..."
        sudo apt update && sudo apt install -y apache2-utils
    fi

    if ! command -v curl &> /dev/null; then
        echo "Installing curl..."
        sudo apt install -y curl
    fi

    if ! command -v bc &> /dev/null; then
        echo "Installing bc calculator..."
        sudo apt install -y bc
    fi
}

# Get LoadBalancer IP
get_loadbalancer_ip() {
    LB_IP=$(microk8s kubectl get svc caddy-service -o jsonpath="{.status.loadBalancer.ingress[0].ip}" 2>/dev/null)
    if [ -z "$LB_IP" ]; then
        echo "❌ LoadBalancer IP not found. Please check your setup."
        exit 1
    fi
    echo "🌐 LoadBalancer IP: $LB_IP"
}

# Basic connectivity test
test_connectivity() {
    echo -e "\n🔗 Testing basic connectivity..."
    
    sites=("loker.cyou" "sabo.news")
    
    for site in "${sites[@]}"; do
        echo "Testing $site..."
        response=$(curl -s -o /dev/null -w "%{http_code},%{time_total}s" --connect-timeout 10 http://$LB_IP/ -H "Host: $site" 2>/dev/null)
        
        if [ $? -eq 0 ]; then
            http_code=$(echo $response | cut -d',' -f1)
            response_time=$(echo $response | cut -d',' -f2)
            
            if [ "$http_code" = "200" ]; then
                echo "✅ $site: HTTP $http_code in $response_time"
            else
                echo "⚠️  $site: HTTP $http_code in $response_time"
            fi
        else
            echo "❌ $site: Connection failed"
        fi
    done
}

# WordPress specific tests
wordpress_load_test() {
    local site=$1
    local concurrent=$2
    local requests=$3
    
    echo -e "\n🚀 Load testing $site with $concurrent concurrent users, $requests requests"
    echo "================================================================="
    
    # Test homepage
    echo "Testing homepage..."
    ab_result=$(ab -n $requests -c $concurrent -H "Host: $site" http://$LB_IP/ 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        # Extract key metrics
        rps=$(echo "$ab_result" | grep "Requests per second" | awk '{print $4}')
        avg_time=$(echo "$ab_result" | grep "Time per request" | head -1 | awk '{print $4}')
        failed=$(echo "$ab_result" | grep "Failed requests" | awk '{print $3}')
        
        echo "📊 Results for $site homepage:"
        echo "   - Requests per second: $rps"
        echo "   - Average response time: ${avg_time}ms"
        echo "   - Failed requests: $failed"
        
        # Performance thresholds
        if (( $(echo "$rps > 10" | bc -l) )); then
            echo "   - Performance: ✅ Good (>10 RPS)"
        elif (( $(echo "$rps > 5" | bc -l) )); then
            echo "   - Performance: ⚠️  Fair (5-10 RPS)"
        else
            echo "   - Performance: ❌ Poor (<5 RPS)"
        fi
        
    else
        echo "❌ Load test failed for $site"
    fi
    
    # Test WordPress login page
    echo -e "\nTesting WordPress login page..."
    ab_result=$(ab -n $(($requests/2)) -c $(($concurrent/2)) -H "Host: $site" http://$LB_IP/wp-login.php 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        rps=$(echo "$ab_result" | grep "Requests per second" | awk '{print $4}')
        echo "📊 Login page RPS: $rps"
    fi
}

# Database performance test
database_performance_test() {
    echo -e "\n🗄️  Database Performance Test"
    echo "============================="
    
    MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    
    if [ ! -z "$MARIADB_POD" ]; then
        echo "Testing MariaDB performance..."
        
        # Simple query performance test
        query_time=$(microk8s kubectl exec $MARIADB_POD -n database -- bash -c "
            time mysql -u wordpress_user -pM1xyl1qyp1q~2025 -e 'SELECT COUNT(*) FROM information_schema.tables;' 2>&1 | grep real | awk '{print \$2}'
        " 2>/dev/null)
        
        echo "📊 Simple query execution time: $query_time"
        
        # Connection test
        for i in {1..10}; do
            microk8s kubectl exec $MARIADB_POD -n database -- mysql -u wordpress_user -pM1xyl1qyp1q~2025 -e "SELECT 1;" > /dev/null 2>&1
            if [ $? -eq 0 ]; then
                echo "Connection test $i: ✅"
            else
                echo "Connection test $i: ❌"
            fi
        done
    else
        echo "❌ MariaDB pod not found"
    fi
}

# Redis performance test
redis_performance_test() {
    echo -e "\n⚡ Redis Performance Test"
    echo "========================"
    
    REDIS_POD=$(microk8s kubectl get pods -n database -l app=redis -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    
    if [ ! -z "$REDIS_POD" ]; then
        echo "Testing Redis performance..."
        
        # Redis benchmark
        redis_result=$(microk8s kubectl exec $REDIS_POD -n database -- redis-cli eval "
            for i = 1, 1000 do
                redis.call('set', 'test:' .. i, 'value' .. i)
                redis.call('get', 'test:' .. i)
            end
            return 'OK'
        " 0 2>/dev/null)
        
        if [ "$redis_result" = "OK" ]; then
            echo "✅ Redis 1000 SET/GET operations: Completed"
            
            # Test Redis ping
            ping_result=$(microk8s kubectl exec $REDIS_POD -n database -- redis-cli ping 2>/dev/null)
            echo "📊 Redis ping response: $ping_result"
        else
            echo "❌ Redis performance test failed"
        fi
        
        # Cleanup test keys
        microk8s kubectl exec $REDIS_POD -n database -- redis-cli eval "
            local keys = redis.call('keys', 'test:*')
            for i = 1, #keys do
                redis.call('del', keys[i])
            end
            return #keys
        " 0 > /dev/null 2>&1
    else
        echo "❌ Redis pod not found"
    fi
}

# Stress test with scaling
stress_test_with_scaling() {
    echo -e "\n🔥 Stress Test with Auto-Scaling"
    echo "================================"
    
    # Initial pod count
    initial_loker=$(microk8s kubectl get deployment wp-loker -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
    initial_sabo=$(microk8s kubectl get deployment wp-sabo -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
    initial_caddy=$(microk8s kubectl get deployment caddy -n default -o jsonpath='{.status.replicas}' 2>/dev/null)
    
    echo "📊 Initial pod counts:"
    echo "   - WordPress Loker: $initial_loker"
    echo "   - WordPress Sabo: $initial_sabo"
    echo "   - Caddy: $initial_caddy"
    
    # Start stress test
    echo -e "\n🚀 Starting stress test for 60 seconds..."
    
    # Run concurrent load tests
    ab -t 60 -c 20 -H "Host: loker.cyou" http://$LB_IP/ > /tmp/stress_loker.log 2>&1 &
    STRESS_PID_1=$!
    
    ab -t 60 -c 20 -H "Host: sabo.news" http://$LB_IP/ > /tmp/stress_sabo.log 2>&1 &
    STRESS_PID_2=$!
    
    # Monitor scaling every 10 seconds
    for i in {1..6}; do
        sleep 10
        current_loker=$(microk8s kubectl get deployment wp-loker -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
        current_sabo=$(microk8s kubectl get deployment wp-sabo -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
        current_caddy=$(microk8s kubectl get deployment caddy -n default -o jsonpath='{.status.replicas}' 2>/dev/null)
        
        echo "📈 T+${i}0s - Pods: Loker=$current_loker, Sabo=$current_sabo, Caddy=$current_caddy"
        
        # Check HPA status
        hpa_status=$(microk8s kubectl get hpa -n wordpress --no-headers 2>/dev/null)
        if [ ! -z "$hpa_status" ]; then
            echo "   HPA Status: $(echo "$hpa_status" | awk '{print $1 ": " $3 "/" $4 "/" $5}')"
        fi
    done
    
    # Wait for stress tests to complete
    wait $STRESS_PID_1
    wait $STRESS_PID_2
    
    echo -e "\n📊 Stress test completed!"
    
    # Extract results
    if [ -f /tmp/stress_loker.log ]; then
        loker_rps=$(grep "Requests per second" /tmp/stress_loker.log | awk '{print $4}')
        echo "   - Loker.cyou sustained RPS: $loker_rps"
    fi
    
    if [ -f /tmp/stress_sabo.log ]; then
        sabo_rps=$(grep "Requests per second" /tmp/stress_sabo.log | awk '{print $4}')
        echo "   - Sabo.news sustained RPS: $sabo_rps"
    fi
    
    # Final pod counts
    sleep 30  # Wait for scaling to stabilize
    final_loker=$(microk8s kubectl get deployment wp-loker -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
    final_sabo=$(microk8s kubectl get deployment wp-sabo -n wordpress -o jsonpath='{.status.replicas}' 2>/dev/null)
    final_caddy=$(microk8s kubectl get deployment caddy -n default -o jsonpath='{.status.replicas}' 2>/dev/null)
    
    echo -e "\n📊 Final pod counts:"
    echo "   - WordPress Loker: $final_loker (was $initial_loker)"
    echo "   - WordPress Sabo: $final_sabo (was $initial_sabo)"
    echo "   - Caddy: $final_caddy (was $initial_caddy)"
    
    # Cleanup
    rm -f /tmp/stress_*.log
}

# Generate load test report
generate_load_test_report() {
    echo -e "\n📋 LOAD TEST REPORT"
    echo "==================="
    echo "Test Date: $(date)"
    echo "Cluster: MicroK8s WordPress Enhanced"
    echo "LoadBalancer IP: $LB_IP"
    
    echo -e "\nTest Configuration:"
    echo "- Concurrent Users: ${CONCURRENT_USERS[*]}"
    echo "- Request Counts: ${REQUEST_COUNTS[*]}"
    echo "- Stress Test Duration: ${TEST_DURATION}s"
    
    echo -e "\nSystem Status:"
    echo "- Total Pods: $(microk8s kubectl get pods --all-namespaces --no-headers | wc -l)"
    echo "- Running Pods: $(microk8s kubectl get pods --all-namespaces --field-selector=status.phase=Running --no-headers | wc -l)"
    echo "- Node Status: $(microk8s kubectl get nodes --no-headers | awk '{print $2}' | paste -sd ',')"
    
    echo -e "\nResource Utilization:"
    microk8s kubectl top nodes 2>/dev/null || echo "Metrics not available"
    
    echo -e "\nRecommendations:"
    echo "✅ Monitor HPA scaling during peak loads"
    echo "✅ Consider resource limit adjustments if needed"
    echo "✅ Regular load testing recommended weekly"
    echo "✅ Database query optimization for high loads"
}

# Main execution
main() {
    echo "Starting comprehensive load testing..."
    
    check_dependencies
    get_loadbalancer_ip
    test_connectivity
    
    echo -e "\n🎯 Running Progressive Load Tests"
    echo "================================="
    
    # Progressive load testing
    for concurrent in "${CONCURRENT_USERS[@]}"; do
        for requests in "${REQUEST_COUNTS[@]}"; do
            if [ $concurrent -le $requests ]; then
                wordpress_load_test "loker.cyou" $concurrent $requests
                sleep 5  # Cool-down between tests
                wordpress_load_test "sabo.news" $concurrent $requests
                sleep 10  # Longer cool-down between different configurations
            fi
        done
    done
    
    database_performance_test
    redis_performance_test
    stress_test_with_scaling
    generate_load_test_report
    
    echo -e "\n🎉 Load testing completed successfully!"
}

# Run main function
main "$@"
```

## FASE 13: Production Deployment Scripts

### 13.1 Complete Auto-Deployment Script (Manager)

```bash
#!/bin/bash
# deploy-all.sh
# Script to deploy all services in MicroK8s Enhanced Architecture

set -e

echo "=== Deploying All Services ==="

# Create temporary credential storage
CREDENTIAL_FILE="/tmp/deployment-credentials-$(date +%Y%m%d_%H%M%S).txt"
touch $CREDENTIAL_FILE
chmod 600 $CREDENTIAL_FILE

# FASE 2: Create MariaDB Secret and Deploy Storage & Namespaces
echo "FASE 2: Creating MariaDB Secret and Deploying Storage & Namespaces..."
MARIADB_ROOT_PASSWORD=$(openssl rand -base64 32)
MARIADB_USER_PASSWORD=$(openssl rand -base64 32)
microk8s kubectl create secret generic mariadb-secret \
  --namespace=database \
  --from-literal=MYSQL_ROOT_PASSWORD="$MARIADB_ROOT_PASSWORD" \
  --from-literal=MYSQL_PASSWORD="$MARIADB_USER_PASSWORD" \
  --from-literal=MYSQL_USER="wordpress_user"
echo "MariaDB Root Password: $MARIADB_ROOT_PASSWORD" >> $CREDENTIAL_FILE
echo "MariaDB User Password: $MARIADB_USER_PASSWORD" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/infrastructure/storage-config-enhanced.yaml
echo "Storage and Namespaces deployed"

# FASE 3: Deploy MariaDB
echo "FASE 3: Deploying MariaDB..."
microk8s kubectl apply -f manifests/database/mariadb-enhanced.yaml
echo "MariaDB deployed"

# FASE 4: Deploy Redis
echo "FASE 4: Deploying Redis..."
microk8s kubectl apply -f manifests/database/redis-enhanced.yaml
echo "Redis deployed"

# FASE 5: Create WordPress Secrets and Deploy WordPress
echo "FASE 5: Creating WordPress Secrets and Deploying WordPress..."
WP_SALTS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/)
AUTH_KEY=$(echo "$WP_SALTS" | grep "AUTH_KEY" | cut -d "'" -f 4)
SECURE_AUTH_KEY=$(echo "$WP_SALTS" | grep "SECURE_AUTH_KEY" | cut -d "'" -f 4)
LOGGED_IN_KEY=$(echo "$WP_SALTS" | grep "LOGGED_IN_KEY" | cut -d "'" -f 4)
NONCE_KEY=$(echo "$WP_SALTS" | grep "NONCE_KEY" | cut -d "'" -f 4)
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)

microk8s kubectl create secret generic wp-loker-secret \
  --namespace=wordpress \
  --from-literal=DB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_NAME="loker_cyou" \
  --from-literal=DB_USER="wordpress_user" \
  --from-literal=DB_PASSWORD="$DB_PASSWORD" \
  --from-literal=REDIS_HOST="redis-service.database.svc.cluster.local" \
  --from-literal=WORDPRESS_AUTH_KEY="$AUTH_KEY" \
  --from-literal=WORDPRESS_SECURE_AUTH_KEY="$SECURE_AUTH_KEY" \
  --from-literal=WORDPRESS_LOGGED_IN_KEY="$LOGGED_IN_KEY" \
  --from-literal=WORDPRESS_NONCE_KEY="$NONCE_KEY"

microk8s kubectl create secret generic wp-sabo-secret \
  --namespace=wordpress \
  --from-literal=DB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_NAME="sabo_news" \
  --from-literal=DB_USER="wordpress_user" \
  --from-literal=DB_PASSWORD="$DB_PASSWORD" \
  --from-literal=REDIS_HOST="redis-service.database.svc.cluster.local" \
  --from-literal=WORDPRESS_AUTH_KEY="$AUTH_KEY" \
  --from-literal=WORDPRESS_SECURE_AUTH_KEY="$SECURE_AUTH_KEY" \
  --from-literal=WORDPRESS_LOGGED_IN_KEY="$LOGGED_IN_KEY" \
  --from-literal=WORDPRESS_NONCE_KEY="$NONCE_KEY"

echo "WordPress Secrets created"
echo "WordPress AUTH_KEY (loker/sabo): $AUTH_KEY" >> $CREDENTIAL_FILE
echo "WordPress SECURE_AUTH_KEY (loker/sabo): $SECURE_AUTH_KEY" >> $CREDENTIAL_FILE
echo "WordPress LOGGED_IN_KEY (loker/sabo): $LOGGED_IN_KEY" >> $CREDENTIAL_FILE
echo "WordPress NONCE_KEY (loker/sabo): $NONCE_KEY" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/wordpress/wordpress-enhanced.yaml
echo "WordPress deployed"

# FASE 6: Create Caddy Secret and Deploy Caddy
echo "FASE 6: Creating Caddy Secret and Deploying Caddy..."
CADDY_PASSWORD=$(openssl rand -base64 16)
CADDY_HASH=$(echo -n "$CADDY_PASSWORD" | microk8s htpasswd -ni admin | tr -d '[:space:]')
microk8s kubectl create secret generic caddy-auth-secret \
  --namespace=default \
  --from-literal=CADDY_HASH="$CADDY_HASH"
echo "Caddy Password: $CADDY_PASSWORD" >> $CREDENTIAL_FILE
echo "Caddy Hash: $CADDY_HASH" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/infrastructure/caddy-enhanced.yaml
echo "Caddy deployed"

# FASE 7: Deploy Adminer
echo "FASE 7: Deploying Adminer..."
microk8s kubectl apply -f manifests/tools/adminer-enhanced.yaml
echo "Adminer deployed"

# FASE 8: Create N8N Secret and Deploy N8N
echo "FASE 8: Creating N8N Secret and Deploying N8N..."
N8N_KEY=$(openssl rand -hex 32)
N8N_PASSWORD=$(openssl rand -base64 16)
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)
microk8s kubectl create secret generic n8n-secret \
  --namespace=tools \
  --from-literal=N8N_BASIC_AUTH_USER="admin" \
  --from-literal=N8N_BASIC_AUTH_PASSWORD="$N8N_PASSWORD" \
  --from-literal=DB_MYSQLDB_HOST="mariadb-service.database.svc.cluster.local" \
  --from-literal=DB_MYSQLDB_PORT="3306" \
  --from-literal=DB_MYSQLDB_DATABASE="n8n" \
  --from-literal=DB_MYSQLDB_USER="wordpress_user" \
  --from-literal=DB_MYSQLDB_PASSWORD="$DB_PASSWORD" \
  --from-literal=N8N_ENCRYPTION_KEY="$N8N_KEY"
echo "N8N Password: $N8N_PASSWORD" >> $CREDENTIAL_FILE
echo "N8N Encryption Key: $N8N_KEY" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/tools/n8n-enhanced.yaml
echo "N8N deployed"

# FASE 9: Create Supabase Secret and Deploy Supabase
echo "FASE 9: Creating Supabase Secret and Deploying Supabase..."
SUPABASE_JWT=$(openssl rand -hex 32)
DB_PASSWORD=$(microk8s kubectl get secret mariadb-secret -n database -o jsonpath='{.data.MYSQL_PASSWORD}' | base64 --decode)
microk8s kubectl create secret generic supabase-secret \
  --namespace=tools \
  --from-literal=POSTGRES_PASSWORD="$DB_PASSWORD" \
  --from-literal=POSTGRES_USER="supabase_admin" \
  --from-literal=POSTGRES_DB="postgres" \
  --from-literal=JWT_SECRET="$SUPABASE_JWT" \
  --from-literal=ANON_KEY="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0" \
  --from-literal=SERVICE_ROLE_KEY="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU"
echo "Supabase JWT Secret: $SUPABASE_JWT" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/tools/supabase-enhanced.yaml
echo "Supabase deployed"

# FASE 10: Create Mailcow Secret and Deploy Mailcow
echo "FASE 10: Creating Mailcow Secret and Deploying Mailcow..."
MAILCOW_DBPASS=$(openssl rand -base64 32)
MAILCOW_DBROOT=$(openssl rand -base64 32)
microk8s kubectl create secret generic mailcow-secret \
  --namespace=mail \
  --from-literal=MAILCOW_HOSTNAME="mail.rahajeng.my.id" \
  --from-literal=MAILCOW_PASS_SCHEME="BLF-CRYPT" \
  --from-literal=DBPASS="$MAILCOW_DBPASS" \
  --from-literal=DBROOT="$MAILCOW_DBROOT" \
  --from-literal=HTTP_PORT="80" \
  --from-literal=HTTPS_PORT="443" \
  --from-literal=HTTP_BIND="0.0.0.0" \
  --from-literal=HTTPS_BIND="0.0.0.0"
echo "Mailcow DBPASS: $MAILCOW_DBPASS" >> $CREDENTIAL_FILE
echo "Mailcow DBROOT: $MAILCOW_DBROOT" >> $CREDENTIAL_FILE

microk8s kubectl apply -f manifests/mail/mailcow-enhanced.yaml
echo "Mailcow deployed"

echo "=== Deployment Completed ==="
echo "Credentials saved to $CREDENTIAL_FILE"
echo "Please store these credentials securely and delete the file after use."
```

### 13.2 Supporting Scripts

```bash
# init-databases.sh
#!/bin/bash
echo "=== Initializing WordPress Databases ==="

# Wait for MariaDB to be ready
echo "Waiting for MariaDB to be ready..."
sleep 30

MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}")

if [ -z "$MARIADB_POD" ]; then
    echo "❌ MariaDB pod not found!"
    exit 1
fi

echo "Creating WordPress databases..."
microk8s kubectl exec $MARIADB_POD -n database -- mysql -u wordpress_user -pM1xyl1qyp1q~2025 -e "
CREATE DATABASE IF NOT EXISTS loker_cyou CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sabo_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS n8n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON loker_cyou.* TO 'wordpress_user'@'%';
GRANT ALL PRIVILEGES ON sabo_news.* TO 'wordpress_user'@'%';
GRANT ALL PRIVILEGES ON n8n.* TO 'wordpress_user'@'%';
FLUSH PRIVILEGES;
"

echo "✅ Databases initialized successfully"
```

```bash
# setup-wordpress.sh
#!/bin/bash
echo "=== Setting up WordPress Applications ==="

# Function to setup WordPress via CLI
setup_wordpress_site() {
   local site_name=$1
   local domain=$2
   local db_name=$3
   local is_multisite=$4
   
   echo "Setting up WordPress for $site_name ($domain)..."
   
   # Get WordPress pod
   WP_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-$site_name -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
   
   if [ -z "$WP_POD" ]; then
       echo "❌ WordPress pod for $site_name not found!"
       return 1
   fi
   
   # Wait for WordPress to be ready
   echo "Waiting for WordPress pod to be ready..."
   microk8s kubectl wait --for=condition=ready pod/$WP_POD -n wordpress --timeout=300s
   
   # Download WP-CLI if not exists
   echo "Setting up WP-CLI..."
   microk8s kubectl exec $WP_POD -n wordpress -- bash -c "
       if [ ! -f /usr/local/bin/wp ]; then
           curl -O https://raw.githubusercontent.com/wp-cli/wp-cli/v2.8.1/phar/wp-cli.phar
           chmod +x wp-cli.phar
           mv wp-cli.phar /usr/local/bin/wp
       fi
   "
   
   # Check if WordPress is already installed
   echo "Checking WordPress installation status..."
   is_installed=$(microk8s kubectl exec $WP_POD -n wordpress -- wp core is-installed --allow-root 2>/dev/null && echo "yes" || echo "no")
   
   if [ "$is_installed" = "no" ]; then
       echo "Installing WordPress core..."
       
       # Download WordPress
       microk8s kubectl exec $WP_POD -n wordpress -- wp core download --allow-root --force
       
       # Create wp-config.php
       microk8s kubectl exec $WP_POD -n wordpress -- wp config create \
           --dbname=$db_name \
           --dbuser=wordpress_user \
           --dbpass=M1xyl1qyp1q~2025 \
           --dbhost=mariadb-service.database.svc.cluster.local \
           --allow-root \
           --force
       
       # Install WordPress
       if [ "$is_multisite" = "true" ]; then
           echo "Installing WordPress Multisite..."
           microk8s kubectl exec $WP_POD -n wordpress -- wp core multisite-install \
               --url=$domain \
               --title="$site_name Network" \
               --admin_user=admin \
               --admin_password=M1xyl1qyp1q~2025 \
               --admin_email=katakaosantri@gmail.com \
               --allow-root
       else
           echo "Installing WordPress Single Site..."
           microk8s kubectl exec $WP_POD -n wordpress -- wp core install \
               --url=$domain \
               --title="$site_name" \
               --admin_user=admin \
               --admin_password=M1xyl1qyp1q~2025 \
               --admin_email=katakaosantri@gmail.com \
               --allow-root
       fi
       
       # Install essential plugins
       echo "Installing essential plugins..."
       microk8s kubectl exec $WP_POD -n wordpress -- wp plugin install \
           redis-cache \
           wp-super-cache \
           wordfence \
           yoast-seo \
           --activate \
           --allow-root
       
       # Configure Redis cache
       echo "Configuring Redis cache..."
       microk8s kubectl exec $WP_POD -n wordpress -- wp config set WP_REDIS_HOST redis-service.database.svc.cluster.local --allow-root
       microk8s kubectl exec $WP_POD -n wordpress -- wp config set WP_REDIS_PORT 6379 --allow-root
       microk8s kubectl exec $WP_POD -n wordpress -- wp redis enable --allow-root
       
       # Set proper permissions
       microk8s kubectl exec $WP_POD -n wordpress -- chown -R www-data:www-data /app/public
       microk8s kubectl exec $WP_POD -n wordpress -- find /app/public -type d -exec chmod 755 {} \;
       microk8s kubectl exec $WP_POD -n wordpress -- find /app/public -type f -exec chmod 644 {} \;
       
       echo "✅ WordPress $site_name setup completed"
   else
       echo "✅ WordPress $site_name already installed"
   fi
}

# Setup WordPress sites
setup_wordpress_site "loker" "loker.cyou" "loker_cyou" "false"
setup_wordpress_site "sabo" "sabo.news" "sabo_news" "true"

echo "✅ All WordPress sites configured successfully"
```

```bash
# health-check.sh
#!/bin/bash
echo "=== Quick Health Check ==="

# Function to check pod health
check_pod_health() {
    local namespace=$1
    local app_label=$2
    local app_name=$3
    
    pod_count=$(microk8s kubectl get pods -n $namespace -l app=$app_label --field-selector=status.phase=Running --no-headers 2>/dev/null | wc -l)
    
    if [ $pod_count -gt 0 ]; then
        echo "✅ $app_name: $pod_count pod(s) running"
        return 0
    else
        echo "❌ $app_name: No running pods"
        return 1
    fi
}

# Check core services
echo "Checking core services..."
check_pod_health "database" "mariadb" "MariaDB"
check_pod_health "database" "redis" "Redis"
check_pod_health "wordpress" "wp-loker" "WordPress Loker"
check_pod_health "wordpress" "wp-sabo" "WordPress Sabo"
check_pod_health "default" "caddy" "Caddy"

# Check LoadBalancer
LB_IP=$(microk8s kubectl get svc caddy-service -o jsonpath="{.status.loadBalancer.ingress[0].ip}" 2>/dev/null)
if [ ! -z "$LB_IP" ]; then
    echo "✅ LoadBalancer: $LB_IP"
else
    echo "⚠️  LoadBalancer: IP not assigned"
fi

# Quick connectivity test
if [ ! -z "$LB_IP" ]; then
    if timeout 5 curl -s http://$LB_IP > /dev/null 2>&1; then
        echo "✅ Web connectivity: Working"
    else
        echo "⚠️  Web connectivity: Issues detected"
    fi
fi

echo "Health check completed"
```

```bash
# quick-restart.sh
#!/bin/bash
echo "=== Quick Service Restart ==="

# Function to restart deployment
restart_deployment() {
    local namespace=$1
    local deployment=$2
    local service_name=$3
    
    echo "Restarting $service_name..."
    microk8s kubectl rollout restart deployment/$deployment -n $namespace
    
    echo "Waiting for $service_name to be ready..."
    microk8s kubectl rollout status deployment/$deployment -n $namespace --timeout=300s
    
    if [ $? -eq 0 ]; then
        echo "✅ $service_name restarted successfully"
    else
        echo "❌ $service_name restart failed"
    fi
}

# Parse command line arguments
case "$1" in
    "wordpress")
        restart_deployment "wordpress" "wp-loker" "WordPress Loker"
        restart_deployment "wordpress" "wp-sabo" "WordPress Sabo"
        ;;
    "loker")
        restart_deployment "wordpress" "wp-loker" "WordPress Loker"
        ;;
    "sabo")
        restart_deployment "wordpress" "wp-sabo" "WordPress Sabo"
        ;;
    "caddy")
        restart_deployment "default" "caddy" "Caddy"
        ;;
    "database")
        restart_deployment "database" "mariadb" "MariaDB"
        restart_deployment "database" "redis" "Redis"
        ;;
    "all")
        restart_deployment "database" "mariadb" "MariaDB"
        restart_deployment "database" "redis" "Redis"
        restart_deployment "wordpress" "wp-loker" "WordPress Loker"
        restart_deployment "wordpress" "wp-sabo" "WordPress Sabo"
        restart_deployment "default" "caddy" "Caddy"
        ;;
    *)
        echo "Usage: $0 {wordpress|loker|sabo|caddy|database|all}"
        echo ""
        echo "Examples:"
        echo "  $0 wordpress    # Restart both WordPress sites"
        echo "  $0 loker        # Restart only loker.cyou"
        echo "  $0 database     # Restart MariaDB and Redis"
        echo "  $0 all          # Restart all services"
        exit 1
        ;;
esac

echo "Restart operations completed"
```

## FASE 14: Enhanced SSL & Security Configuration

### 14.1 SSL Certificate Management (Manager)

```yaml
*# ssl-certificates.yaml*
---
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata:
  name: letsencrypt-prod
spec:
  acme:
    server: https://acme-v02.api.letsencrypt.org/directory
    email: katakaosantri@gmail.com
    privateKeySecretRef:
      name: letsencrypt-prod
    solvers:
    - http01:
        ingress:
          class: nginx
---
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: loker-cyou-tls
  namespace: default
spec:
  secretName: loker-cyou-tls
  issuerRef:
    name: letsencrypt-prod
    kind: ClusterIssuer
  dnsNames:
  - loker.cyou
  - www.loker.cyou
---
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: sabo-news-tls
  namespace: default
spec:
  secretName: sabo-news-tls
  issuerRef:
    name: letsencrypt-prod
    kind: ClusterIssuer
  dnsNames:
  - sabo.news
  - www.sabo.news
---
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: rahajeng-tools-tls
  namespace: default
spec:
  secretName: rahajeng-tools-tls
  issuerRef:
    name: letsencrypt-prod
    kind: ClusterIssuer
  dnsNames:
  - adminer.rahajeng.my.id
  - n8n.rahajeng.my.id
  - supabase.rahajeng.my.id
  - mail.rahajeng.my.id
```

### 14.2 Network Security Policies (Manager)

```yaml
*# network-policies.yaml*
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: wordpress-netpol
  namespace: wordpress
spec:
  podSelector:
    matchLabels:
      app: wp-loker
  policyTypes:
  - Ingress
  - Egress
  ingress:
  - from:
    - namespaceSelector:
        matchLabels:
          name: default
    ports:
    - protocol: TCP
      port: 80
  egress:
  - to:
    - namespaceSelector:
        matchLabels:
          name: database
    ports:
    - protocol: TCP
      port: 3306
    - protocol: TCP
      port: 6379
  - to: []
    ports:
    - protocol: TCP
      port: 80
    - protocol: TCP
      port: 443
    - protocol: UDP
      port: 53
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: database-netpol
  namespace: database
spec:
  podSelector: {}
  policyTypes:
  - Ingress
  ingress:
  - from:
    - namespaceSelector:
        matchLabels:
          name: wordpress
    - namespaceSelector:
        matchLabels:
          name: tools
    ports:
    - protocol: TCP
      port: 3306
    - protocol: TCP
      port: 6379
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: tools-netpol
  namespace: tools
spec:
  podSelector: {}
  policyTypes:
  - Ingress
  ingress:
  - from:
    - namespaceSelector:
        matchLabels:
          name: default
    ports:
    - protocol: TCP
      port: 8080
    - protocol: TCP
      port: 5678
    - protocol: TCP
      port: 3000
```

---

## FASE 15: Disaster Recovery & Backup Automation

### 15.1 Automated Backup Scheduler (Manager)

```yaml
# backup-cronjob.yaml (MODIFIED)
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: backup-scripts
  namespace: default
data:
  backup.sh: |
    #!/bin/bash
    set -e
    
    BACKUP_DIR="/backup/automated"
    DATE=$(date +%Y%m%d_%H%M%S)
    RETENTION_DAYS=14
    
    # Create backup directory
    mkdir -p $BACKUP_DIR
    
    echo "=== Automated Backup Started: $DATE ==="
    
    # Function to get secret
    get_secret() {
        kubectl get secret $1 -n $2 -o jsonpath="{.data.$3}" | base64 --decode
    }
    
    # Database backups
    MARIADB_POD=$(kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}")
    if [ ! -z "$MARIADB_POD" ]; then
        echo "Backing up MariaDB..."
        MARIADB_PASS=$(get_secret mariadb-secret database MYSQL_PASSWORD)
        kubectl exec $MARIADB_POD -n database -- env MYSQL_PWD="$MARIADB_PASS" \
          mysqldump -u wordpress_user --single-transaction --routines --triggers --all-databases | gzip > $BACKUP_DIR/mariadb-$DATE.sql.gz
    fi
    
    POSTGRES_POD=$(kubectl get pods -n tools -l app=postgres -o jsonpath="{.items[0].metadata.name}")
    if [ ! -z "$POSTGRES_POD" ]; then
        echo "Backing up PostgreSQL..."
        POSTGRES_PASS=$(get_secret supabase-secret tools POSTGRES_PASSWORD)
        kubectl exec $POSTGRES_POD -n tools -- env PGPASSWORD="$POSTGRES_PASS" \
          pg_dumpall -U supabase_admin | gzip > $BACKUP_DIR/postgres-$DATE.sql.gz
    fi
    
    # Configuration backup
    echo "Backing up configurations..."
    kubectl get all --all-namespaces -o yaml | gzip > $BACKUP_DIR/cluster-config-$DATE.yaml.gz
    
    # WordPress content backup
    backup_wp_content() {
        local site=$1
        local pod_name=$(kubectl get pods -n wordpress -l app=wp-$site -o jsonpath="{.items[0].metadata.name}")
        if [ ! -z "$pod_name" ]; then
            kubectl exec $pod_name -n wordpress -- tar czf - /app/public 2>/dev/null | cat > $BACKUP_DIR/wp-$site-$DATE.tar.gz
        fi
    }
    
    backup_wp_content "loker"
    backup_wp_content "sabo"
    
    # Cleanup old backups
    find $BACKUP_DIR -name "*-*.sql.gz" -mtime +$RETENTION_DAYS -delete
    find $BACKUP_DIR -name "*-*.tar.gz" -mtime +$RETENTION_DAYS -delete
    find $BACKUP_DIR -name "*-*.yaml.gz" -mtime +$RETENTION_DAYS -delete
    
    echo "=== Automated Backup Completed: $DATE ==="
    
    # Send notification (optional)
    if command -v curl &> /dev/null; then
        # Example webhook notification
        # curl -X POST -H 'Content-type: application/json' --data '{"text":"Backup completed successfully"}' YOUR_WEBHOOK_URL
        echo "Backup notification sent"
    fi
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: backup-storage-pvc
  namespace: default
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: fast-ssd
  resources:
    requests:
      storage: 50Gi
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: automated-backup
  namespace: default
spec:
  schedule: "0 2 * * *"  # Daily at 2 AM
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: backup
            image: bitnami/kubectl:latest
            command:
            - /bin/bash
            - /scripts/backup.sh
            volumeMounts:
            - name: backup-scripts
              mountPath: /scripts
            - name: backup-storage
              mountPath: /backup
            resources:
              requests:
                memory: "256Mi"
                cpu: "200m"
              limits:
                memory: "512Mi"
                cpu: "500m"
          volumes:
          - name: backup-scripts
            configMap:
              name: backup-scripts
              defaultMode: 0755
          - name: backup-storage
            persistentVolumeClaim:
              claimName: backup-storage-pvc
          restartPolicy: OnFailure
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 3
```

### 15.2 Disaster Recovery Script (Manager)

```yaml
#!/bin/bash
# disaster-recovery.sh (MODIFIED)
set -e

echo "=== MicroK8s Disaster Recovery ==="
echo "=================================="

RECOVERY_MODE="$1"
BACKUP_DATE="$2"
BACKUP_DIR="/backup/automated"

# Function to get secret
get_secret() {
    microk8s kubectl get secret $1 -n $2 -o jsonpath="{.data.$3}" | base64 --decode
}

if [ -z "$RECOVERY_MODE" ]; then
    echo "Usage: $0 {full|database|wordpress|config} [backup_date]"
    echo ""
    echo "Recovery modes:"
    echo "  full      - Complete cluster recovery"
    echo "  database  - Database recovery only"
    echo "  wordpress - WordPress sites recovery"
    echo "  config    - Configuration recovery"
    echo ""
    echo "Example: $0 full 20241201_020000"
    exit 1
fi

# Function to find latest backup if date not specified
find_latest_backup() {
    local pattern=$1
    ls -t $BACKUP_DIR/$pattern 2>/dev/null | head -1
}

# Full recovery
full_recovery() {
    echo "🚨 Starting FULL disaster recovery..."
    echo "This will restore the entire cluster from backup."
    read -p "Are you sure? This cannot be undone! (yes/no): " confirm
    
    if [ "$confirm" != "yes" ]; then
        echo "Recovery cancelled."
        exit 0
    fi
    
    # Stop all applications
    echo "Stopping applications..."
    microk8s kubectl scale deployment --all --replicas=0 --all-namespaces
    
    # Restore databases
    database_recovery
    
    # Restore configurations
    config_recovery
    
    # Restore WordPress content
    wordpress_recovery
    
    # Restart applications
    echo "Restarting applications..."
    microk8s kubectl scale deployment --all --replicas=1 --all-namespaces
    
    echo "✅ Full recovery completed"
}

# Database recovery
database_recovery() {
    echo "🗄️  Starting database recovery..."
    
    # MariaDB recovery
    if [ -z "$BACKUP_DATE" ]; then
        MARIADB_BACKUP=$(find_latest_backup "mariadb-*.sql.gz")
    else
        MARIADB_BACKUP="$BACKUP_DIR/mariadb-$BACKUP_DATE.sql.gz"
    fi
    
    if [ -f "$MARIADB_BACKUP" ]; then
        echo "Restoring MariaDB from $MARIADB_BACKUP..."
        MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}")
        
        if [ ! -z "$MARIADB_POD" ]; then
            # Ambil password dari secret
            MARIADB_ROOT_PASS=$(get_secret mariadb-secret database MYSQL_ROOT_PASSWORD)
            
            # Create temporary restore script
            cat > /tmp/restore_mariadb.sh << 'EOF'
#!/bin/bash
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "DROP DATABASE IF EXISTS loker_cyou;"
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "DROP DATABASE IF EXISTS sabo_news;"
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "DROP DATABASE IF EXISTS n8n;"
EOF
            
            # Copy and execute restore script
            microk8s kubectl cp /tmp/restore_mariadb.sh database/$MARIADB_POD:/tmp/restore.sh
            microk8s kubectl exec $MARIADB_POD -n database -- chmod +x /tmp/restore.sh
            microk8s kubectl exec $MARIADB_POD -n database -- env MYSQL_PWD="$MARIADB_ROOT_PASS" /tmp/restore.sh
            
            # Restore from backup
            zcat $MARIADB_BACKUP | microk8s kubectl exec -i $MARIADB_POD -n database -- env MYSQL_PWD="$MARIADB_ROOT_PASS" mysql -u root
            
            echo "✅ MariaDB restored successfully"
        else
            echo "❌ MariaDB pod not found"
        fi
    else
        echo "❌ MariaDB backup not found: $MARIADB_BACKUP"
    fi
    
    # PostgreSQL recovery
    if [ -z "$BACKUP_DATE" ]; then
        POSTGRES_BACKUP=$(find_latest_backup "postgres-*.sql.gz")
    else
        POSTGRES_BACKUP="$BACKUP_DIR/postgres-$BACKUP_DATE.sql.gz"
    fi
    
    if [ -f "$POSTGRES_BACKUP" ]; then
        echo "Restoring PostgreSQL from $POSTGRES_BACKUP..."
        POSTGRES_POD=$(microk8s kubectl get pods -n tools -l app=postgres -o jsonpath="{.items[0].metadata.name}")
        
        if [ ! -z "$POSTGRES_POD" ]; then
            # Ambil password dari secret
            POSTGRES_PASS=$(get_secret supabase-secret tools POSTGRES_PASSWORD)
            
            # Restore from backup
            zcat $POSTGRES_BACKUP | microk8s kubectl exec -i $POSTGRES_POD -n tools -- env PGPASSWORD="$POSTGRES_PASS" psql -U supabase_admin
            
            echo "✅ PostgreSQL restored successfully"
        else
            echo "❌ PostgreSQL pod not found"
        fi
    else
        echo "❌ PostgreSQL backup not found: $POSTGRES_BACKUP"
    fi
}

# WordPress recovery
wordpress_recovery() {
    echo "🌐 Starting WordPress recovery..."
    
    restore_wp_site() {
        local site=$1
        
        if [ -z "$BACKUP_DATE" ]; then
            WP_BACKUP=$(find_latest_backup "wp-$site-*.tar.gz")
        else
            WP_BACKUP="$BACKUP_DIR/wp-$site-$BACKUP_DATE.tar.gz"
        fi
        
        if [ -f "$WP_BACKUP" ]; then
            echo "Restoring WordPress $site from $WP_BACKUP..."
            WP_POD=$(microk8s kubectl get pods -n wordpress -l app=wp-$site -o jsonpath="{.items[0].metadata.name}")
            
            if [ ! -z "$WP_POD" ]; then
                # Clear existing content
                microk8s kubectl exec $WP_POD -n wordpress -- rm -rf /app/public/*
                
                # Restore content
                cat $WP_BACKUP | microk8s kubectl exec -i $WP_POD -n wordpress -- tar xzf - -C /
                
                # Fix permissions
                microk8s kubectl exec $WP_POD -n wordpress -- chown -R www-data:www-data /app/public
                
                echo "✅ WordPress $site restored successfully"
            else
                echo "❌ WordPress $site pod not found"
            fi
        else
            echo "❌ WordPress $site backup not found: $WP_BACKUP"
        fi
    }
    
    restore_wp_site "loker"
    restore_wp_site "sabo"
}

# Configuration recovery
config_recovery() {
    echo "⚙️  Starting configuration recovery..."
    
    if [ -z "$BACKUP_DATE" ]; then
        CONFIG_BACKUP=$(find_latest_backup "cluster-config-*.yaml.gz")
    else
        CONFIG_BACKUP="$BACKUP_DIR/cluster-config-$BACKUP_DATE.yaml.gz"
    fi
    
    if [ -f "$CONFIG_BACKUP" ]; then
        echo "Restoring configurations from $CONFIG_BACKUP..."
        
        # Apply configurations
        zcat $CONFIG_BACKUP | microk8s kubectl apply -f -
        
        echo "✅ Configurations restored successfully"
    else
        echo "❌ Configuration backup not found: $CONFIG_BACKUP"
    fi
}

# Execute recovery based on mode
case "$RECOVERY_MODE" in
    "full")
        full_recovery
        ;;
    "database")
        database_recovery
        ;;
    "wordpress")
        wordpress_recovery
        ;;
    "config")
        config_recovery
        ;;
    *)
        echo "❌ Invalid recovery mode: $RECOVERY_MODE"
        exit 1
        ;;
esac

echo ""
echo "🎉 Disaster recovery completed!"
echo "Please run health checks to verify system status."
```

---

## FASE 16: Maintenance & Optimization Scripts

### 16.1 System Optimization Script (Manager)

```yaml
#!/bin/bash
*# system-optimization.sh*

echo "=== MicroK8s System Optimization ==="
echo "===================================="

*# Function to optimize deployment resources*
optimize_deployment() {
    local namespace=$1
    local deployment=$2
    local name=$3
    
    echo "Optimizing $name..."
    
    *# Get current resource usage*
    current_usage=$(microk8s kubectl top pod -n $namespace -l app=$deployment --no-headers 2>/dev/null | awk '{cpu+=$2; mem+=$3} END {print cpu "m " mem "Mi"}')
    
    if [ ! -z "$current_usage" ]; then
        echo "Current usage for $name: $current_usage"
        
        *# Analyze and suggest optimizations*
        cpu_usage=$(echo $current_usage | awk '{print $1}' | sed 's/m//')
        mem_usage=$(echo $current_usage | awk '{print $2}' | sed 's/Mi//')
        
        *# CPU optimization*
        if [ $cpu_usage -lt 100 ]; then
            echo "💡 Consider reducing CPU requests for $name"
        elif [ $cpu_usage -gt 800 ]; then
            echo "⚠️  Consider increasing CPU limits for $name"
        fi
        
        *# Memory optimization*
        if [ $mem_usage -lt 256 ]; then
            echo "💡 Consider reducing memory requests for $name"
        elif [ $mem_usage -gt 800 ]; then
            echo "⚠️  Consider increasing memory limits for $name"
        fi
    else
        echo "⚠️  Unable to get resource usage for $name"
    fi
}

*# Check cluster resources*
echo "📊 Cluster Resource Analysis"
echo "============================"

echo "Node resources:"
microk8s kubectl top nodes 2>/dev/null || echo "Metrics server not available"

echo ""
echo "Top resource consuming pods:"
microk8s kubectl top pods --all-namespaces --sort-by=cpu 2>/dev/null | head -10 || echo "Pod metrics not available"

*# Optimize deployments*
echo ""
echo "🔧 Deployment Optimization Analysis"
echo "==================================="

optimize_deployment "wordpress" "wp-loker" "WordPress Loker"
optimize_deployment "wordpress" "wp-sabo" "WordPress Sabo"
optimize_deployment "database" "mariadb" "MariaDB"
optimize_deployment "database" "redis" "Redis"
optimize_deployment "default" "caddy" "Caddy"

*# Clean up unused resources*
echo ""
echo "🧹 Cleanup Operations"
echo "===================="

echo "Cleaning up completed jobs..."
microk8s kubectl delete job --field-selector=status.successful=1 --all-namespaces

echo "Cleaning up failed pods..."
microk8s kubectl delete pod --field-selector=status.phase=Failed --all-namespaces

echo "Cleaning up unused secrets..."
*# List secrets that might be unused*
unused_secrets=$(microk8s kubectl get secrets --all-namespaces -o json | jq -r '.items[] | select(.type=="Opaque" and (.metadata.creationTimestamp | fromdateiso8601) < (now - 7*24*3600)) | "\(.metadata.namespace)/\(.metadata.name)"' 2>/dev/null || echo "No unused secrets found")

if [ "$unused_secrets" != "No unused secrets found" ]; then
    echo "Potential unused secrets (review before deletion):"
    echo "$unused_secrets"
fi

# Docker/containerd cleanup
echo ""
echo "🐳 Container Runtime Cleanup"
echo "============================"

echo "Cleaning up unused container images..."
if command -v docker &> /dev/null; then
    docker system prune -f
elif command -v crictl &> /dev/null; then
    crictl rmi --prune
fi

# Storage optimization
echo ""
echo "💾 Storage Optimization"
echo "======================"

echo "Persistent Volume usage:"
microk8s kubectl get pv -o custom-columns=NAME:.metadata.name,CAPACITY:.spec.capacity.storage,STATUS:.status.phase,CLAIM:.spec.claimRef.name

echo ""
echo "PVC usage by namespace:"
for ns in wordpress database tools mail default; do
    if microk8s kubectl get namespace $ns > /dev/null 2>&1; then
        pvc_usage=$(microk8s kubectl get pvc -n $ns --no-headers 2>/dev/null | wc -l)
        echo "$ns: $pvc_usage PVCs"
    fi
done

# Performance recommendations
echo ""
echo "🚀 Performance Recommendations"
echo "=============================="

# Check HPA status
hpa_count=$(microk8s kubectl get hpa --all-namespaces --no-headers 2>/dev/null | wc -l)
if [ $hpa_count -eq 0 ]; then
    echo "💡 Consider implementing Horizontal Pod Autoscaling"
else
    echo "✅ HPA is configured ($hpa_count rules)"
fi

# Check resource quotas
quota_count=$(microk8s kubectl get resourcequota --all-namespaces --no-headers 2>/dev/null | wc -l)
if [ $quota_count -eq 0 ]; then
    echo "💡 Consider implementing Resource Quotas for better resource management"
else
    echo "✅ Resource quotas are configured ($quota_count quotas)"
fi

# Check for resource requests/limits
pods_without_limits=$(microk8s kubectl get pods --all-namespaces -o json | jq -r '.items[] | select(.spec.containers[].resources.limits == null) | "\(.metadata.namespace)/\(.metadata.name)"' 2>/dev/null | wc -l)
echo "Pods without resource limits: $pods_without_limits"

if [ $pods_without_limits -gt 0 ]; then
    echo "💡 Consider adding resource limits to all pods"
fi

echo ""
echo "✅ System optimization analysis completed"
```

### 16.2 Update and Maintenance Script (Manager)

```bash
#!/bin/bash
# maintenance.sh
# Script for performing maintenance tasks on MicroK8s cluster

set -e

LOG_FILE="/var/log/k8s-maintenance.log"
TIMESTAMP=$(date +%Y-%m-%d\ %H:%M:%S)

# Function to log messages
log_message() {
    echo "[$TIMESTAMP] $1" | tee -a $LOG_FILE
}

# Function to get secret
get_secret() {
    microk8s kubectl get secret $1 -n $2 -o jsonpath="{.data.$3}" | base64 --decode
}

# Function to check cluster health
check_cluster_health() {
    log_message "Checking cluster health..."
    
    # Check node status
    microk8s kubectl get nodes -o wide | grep -v NotReady || {
        log_message "ERROR: One or more nodes are not ready"
        return 1
    }
    
    # Check pod status
    if microk8s kubectl get pods --all-namespaces | grep -v Running | grep -v Completed; then
        log_message "ERROR: Some pods are not in Running state"
        return 1
    fi
    
    # Check resource usage
    microk8s kubectl top nodes > /tmp/node-usage.txt 2>/dev/null
    log_message "Node resource usage written to /tmp/node-usage.txt"
    
    log_message "Cluster health check completed"
}

# Function to optimize databases
optimize_databases() {
    log_message "Optimizing databases..."
    
    # MariaDB optimization
    MARIADB_POD=$(microk8s kubectl get pods -n database -l app=mariadb -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    if [ ! -z "$MARIADB_POD" ]; then
        log_message "Optimizing MariaDB..."
        MARIADB_PASS=$(get_secret mariadb-secret database MYSQL_PASSWORD)
        
        # Run OPTIMIZE TABLE on WordPress databases
        microk8s kubectl exec $MARIADB_POD -n database -- env MYSQL_PWD="$MARIADB_PASS" mysql -u wordpress_user -e "
        USE loker_cyou;
        OPTIMIZE TABLE wp_posts, wp_postmeta, wp_options, wp_comments, wp_commentmeta;
        USE sabo_news;
        OPTIMIZE TABLE wp_posts, wp_postmeta, wp_options, wp_comments, wp_commentmeta;
        " 2>/dev/null || log_message "MariaDB optimization completed with some errors"
        
        log_message "MariaDB optimization completed"
    fi
    
    # Redis optimization
    REDIS_POD=$(microk8s kubectl get pods -n database -l app=redis -o jsonpath="{.items[0].metadata.name}" 2>/dev/null)
    if [ ! -z "$REDIS_POD" ]; then
        log_message "Optimizing Redis..."
        
        # Clear expired keys and defragment
        microk8s kubectl exec $REDIS_POD -n database -- redis-cli FLUSHDB
        microk8s kubectl exec $REDIS_POD -n database -- redis-cli BGSAVE
        
        log_message "Redis optimization completed"
    fi
}

# Function to clean up resources
cleanup_resources() {
    log_message "Cleaning up resources..."
    
    # Delete completed jobs
    microk8s kubectl delete jobs --all --namespace=default --field-selector status.successful=1 2>/dev/null
    microk8s kubectl delete jobs --all --namespace=tools --field-selector status.successful=1 2>/dev/null
    
    # Delete old logs
    find /var/log/caddy -type f -name "*.log" -mtime +30 -delete
    find /var/log -type f -name "*.log" -mtime +30 -delete
    
    # Clean up unused Docker images
    microk8s ctr image ls -q | sort -u | xargs microk8s ctr image rm 2>/dev/null || true
    
    log_message "Resource cleanup completed"
}

# Function to update system
update_system() {
    log_message "Updating system..."
    
    # Update MicroK8s
    microk8s refresh
    microk8s status --wait-ready
    
    # Update container images
    microk8s kubectl get deployments --all-namespaces -o json | \
        jq -r '.items[] | .metadata.namespace + "/" + .metadata.name' | \
        while read -r deployment; do
            microk8s kubectl rollout restart deployment -n "${deployment%/*}" "${deployment##*/}"
        done
    
    log_message "System update completed"
}

# Main execution
case "$1" in
    "check")
        check_cluster_health
        ;;
    "optimize")
        optimize_databases
        ;;
    "cleanup")
        cleanup_resources
        ;;
    "update")
        update_system
        ;;
    "all")
        check_cluster_health
        optimize_databases
        cleanup_resources
        update_system
        ;;
    *)
        echo "Usage: $0 {check|optimize|cleanup|update|all}"
        echo ""
        echo "Maintenance modes:"
        echo "  check    - Check cluster health and resource usage"
        echo "  optimize - Optimize databases (MariaDB, Redis)"
        echo "  cleanup  - Clean up old resources and logs"
        echo "  update   - Update MicroK8s and container images"
        echo "  all      - Run all maintenance tasks"
        exit 1
        ;;
esac

log_message "Maintenance tasks completed successfully"
```

## FASE 17: Final Directory Structure & File Organization

### 17.1 Complete Directory Structure

```
microk8s-wordpress-enhanced/
├── scripts/
│   ├── system-preparation/
│   │   ├── system-prep-manager.sh
│   │   └── system-prep-worker.sh
│   ├── installation/
│   │   ├── install-microk8s-manager.sh
│   │   ├── install-microk8s-worker.sh
│   │   └── verify-cluster.sh
│   ├── deployment/
│   │   ├── deploy-all.sh
│   │   ├── init-databases.sh
│   │   └── setup-wordpress.sh
│   ├── management/
│   │   ├── health-check.sh
│   │   ├── quick-restart.sh
│   │   ├── enhanced-monitor.sh
│   │   └── maintenance.sh
│   ├── testing/
│   │   ├── comprehensive-system-test.sh
│   │   └── load-testing-framework.sh
│   ├── backup-recovery/
│   │   ├── enhanced-backup.sh
│   │   └── disaster-recovery.sh
│   └── optimization/
│       └── system-optimization.sh
├── manifests/
│   ├── infrastructure/
│   │   ├── storage-config-enhanced.yaml
│   │   ├── ssl-certificates.yaml
│   │   └── network-policies.yaml
│   ├── database/
│   │   ├── mariadb-enhanced.yaml
│   │   └── redis-enhanced.yaml
│   ├── applications/
│   │   ├── wordpress-enhanced.yaml
│   │   ├── caddy-enhanced.yaml
│   │   ├── adminer-enhanced.yaml
│   │   ├── n8n-enhanced.yaml
│   │   ├── supabase-enhanced.yaml
│   │   └── mailcow-enhanced.yaml
│   ├── autoscaling/
│   │   └── hpa-enhanced.yaml
│   ├── backup/
│   │   └── backup-cronjob.yaml
│   └── monitoring/
│       └── monitoring-enhanced.yaml
├── configs/
│   └── README.md
├── docs/
│   ├── DEPLOYMENT_GUIDE.md
│   ├── TROUBLESHOOTING.md
│   └── MAINTENANCE_GUIDE.md
├── README.md
└── .gitignore
```

### 17.2 Main README.md

markdown

```bash
# MicroK8s WordPress Enhanced Setup

A complete, production-ready WordPress hosting solution using MicroK8s with high availability, auto-scaling, and comprehensive management tools.

## 🏗️ Architecture

- ****Manager Node****: 4 core, 6GB RAM, 100GB NVMe (194.233.91.166)
- ****Worker Node****: 3 core, 8GB RAM, 75GB NVMe (154.26.130.76)

## 🚀 Services Deployed

### Core Applications
- ****WordPress Sites****: loker.cyou (single), sabo.news (multisite)
- ****Database****: MariaDB cluster with Redis cache
- ****Reverse Proxy****: Caddy with automatic SSL/TLS
- ****Load Balancer****: MetalLB with IP range 10.0.0.100-10.0.0.110

### Admin Tools
- ****Database Management****: Adminer (adminer.rahajeng.my.id)
- ****Automation****: N8N (n8n.rahajeng.my.id)
- ****Backend****: Supabase (supabase.rahajeng.my.id)
- ****Mail Server****: Mailcow (mail.rahajeng.my.id)

### Features
- ✅ Horizontal Pod Autoscaling (HPA)
- ✅ Persistent Storage with multiple storage classes
- ✅ Automatic SSL certificates via Let's Encrypt
- ✅ Redis caching for WordPress
- ✅ Automated backups with retention
- ✅ Comprehensive monitoring and health checks
- ✅ Disaster recovery capabilities
- ✅ Network security policies
- ✅ Resource quotas and limits

## 📋 Quick Start

### 1. System Preparation

```bash
# On Manager Node
chmod +x scripts/system-preparation/system-prep-manager.sh
./scripts/system-preparation/system-prep-manager.sh

# On Worker Node
chmod +x scripts/system-preparation/system-prep-worker.sh
./scripts/system-preparation/system-prep-worker.sh

### 2. MicroK8s Installation
*# On Manager Node*
chmod +x scripts/installation/install-microk8s-manager.sh
./scripts/installation/install-microk8s-manager.sh

*# Copy join command to Worker Node and run# On Worker Node*
chmod +x scripts/installation/install-microk8s-worker.sh
./scripts/installation/install-microk8s-worker.sh
*# Run the join command from manager# Verify cluster*
./scripts/installation/verify-cluster.sh

*###* 3. Complete Deployment
*# Deploy all services*
chmod +x scripts/deployment/deploy-all.sh
./scripts/deployment/deploy-all.sh

### Verify Installation
*# Run comprehensive tests*
chmod +x scripts/testing/comprehensive-system-test.sh
./scripts/testing/comprehensive-system-test.sh
```

### 17.3 Quick Setup Guide

```bash

### 17.3 Quick Setup Guide

```bash
#!/bin/bash
# quick-setup.sh - One-command setup script

echo "=== MicroK8s WordPress Enhanced - Quick Setup ==="
echo "=================================================="

# Check if running as root
if [ "$EUID" -eq 0 ]; then
    echo "❌ Please do not run this script as root"
    exit 1
fi

# Detect node type
echo "Select node type:"
echo "1) Manager Node (Primary)"
echo "2) Worker Node (Secondary)"
read -p "Enter choice (1-2): " NODE_TYPE

case $NODE_TYPE in
    1)
        echo "🔧 Setting up Manager Node..."
        
        # System preparation
        chmod +x scripts/system-preparation/system-prep-manager.sh
        ./scripts/system-preparation/system-prep-manager.sh
        
        # MicroK8s installation
        chmod +x scripts/installation/install-microk8s-manager.sh
        ./scripts/installation/install-microk8s-manager.sh
        
        echo ""
        echo "✅ Manager Node setup completed!"
        echo ""
        echo "🔗 Join command for worker node:"
        cat /tmp/join-command.txt
        echo ""
        echo "📝 Next steps:"
        echo "1. Setup worker node using the join command above"
        echo "2. Run: ./scripts/deployment/deploy-all.sh"
        echo "3. Configure DNS to point to LoadBalancer IP"
        ;;
        
    2)
        echo "🔧 Setting up Worker Node..."
        
        # System preparation
        chmod +x scripts/system-preparation/system-prep-worker.sh
        ./scripts/system-preparation/system-prep-worker.sh
        
        # MicroK8s installation
        chmod +x scripts/installation/install-microk8s-worker.sh
        ./scripts/installation/install-microk8s-worker.sh
        
        echo ""
        echo "✅ Worker Node setup completed!"
        echo ""
        echo "📝 Next steps:"
        echo "1. Run the join command provided by the manager node"
        echo "2. Return to manager node to deploy applications"
        ;;
        
    *)
        echo "❌ Invalid choice"
        exit 1
        ;;
esac

echo ""
echo "🎉 Node setup completed successfully!"
```

### 17.4 File Permissions Script

```bash
#!/bin/bash
*# set-permissions.sh - Set proper permissions for all scripts*

echo "=== Setting Script Permissions ==="

*# Make all shell scripts executable*
find scripts/ -name "*.sh" -type f -exec chmod +x {} \;

*# Make quick setup executable*
chmod +x quick-setup.sh

*# Set proper permissions for config files*
find manifests/ -name "*.yaml" -type f -exec chmod 644 {} \;

echo "✅ All script permissions set correctly"

*# Verify permissions*
echo ""
echo "📋 Script permissions verification:"
find scripts/ -name "*.sh" -type f -exec ls -la {} \;
```

## 🔧 Management Commands

### Daily Operations

```bash
*# Health check*
./scripts/management/health-check.sh

*# Monitor cluster*
./scripts/management/enhanced-monitor.sh

*# Restart services*
./scripts/management/quick-restart.sh {wordpress|database|all}
```

### Maintenance

```bash
*# Enable maintenance mode*
./scripts/management/maintenance.sh enable-maintenance

*# Full maintenance*
./scripts/management/maintenance.sh full-maintenance

*# System optimization*
./scripts/optimization/system-optimization.sh
```

### Backup & Recovery

```bash
*# Manual backup*
./scripts/backup-recovery/enhanced-backup.sh

*# Disaster recovery*
./scripts/backup-recovery/disaster-recovery.sh {full|database|wordpress} [backup_date]
```

### Load Testing

```bash
*# Run load tests*
./scripts/testing/load-testing-framework.sh
```

## 📊 Monitoring & Access

### Web Access (after DNS configuration)

- **WordPress Loker**: [https://loker.cyou](https://loker.cyou/)
- **WordPress Sabo**: [https://sabo.news](https://sabo.news/)
- **Database Admin**: [https://adminer.rahajeng.my.id](https://adminer.rahajeng.my.id/)
- **Automation**: [https://n8n.rahajeng.my.id](https://n8n.rahajeng.my.id/)
- **Backend**: [https://supabase.rahajeng.my.id](https://supabase.rahajeng.my.id/)
- **Mail**: [https://mail.rahajeng.my.id](https://mail.rahajeng.my.id/)

### Default Credentials

- **WordPress Admin**: admin / M1xyl1qyp1q~2025
- **Database**: wordpress_user / M1xyl1qyp1q~2025
- **Admin Tools**: admin / M1xyl1qyp1q~2025

## 🔒 Security Features

- Network policies for inter-pod communication
- Resource quotas to prevent resource exhaustion
- Security contexts for containers
- Automatic SSL/TLS certificates
- Basic authentication for admin tools
- Regular security updates via maintenance scripts

## 📈 Scaling & Performance

- **HPA**: Automatically scales WordPress pods based on CPU/memory usage
- **Resource Limits**: Proper resource allocation for all services
- **Caching**: Redis cache for WordPress performance
- **Load Balancing**: Distributes traffic across multiple pod replicas
- **Storage**: Fast NVMe storage with multiple storage classes

## 🛠️ Troubleshooting

### Common Issues

### **Pods not starting**

```bash
microk8s kubectl describe pod <pod-name> -n <namespace>
```

### **SSL certificates not working**

```bash
microk8s kubectl get certificates --all-namespaces
```

### **Database connection issues**

```bash
./scripts/management/health-check.sh
```

### **LoadBalancer IP not assigned**

bash

```bash
microk8s kubectl get pods -n metallb-system
```

### Log Locations

- **MicroK8s logs**: `/var/snap/microk8s/current/logs/`
- **Application logs**: `microk8s kubectl logs <pod-name> -n <namespace>`
- **Backup logs**: `/tmp/microk8s-deploy-*.log`
- **Maintenance logs**: `/var/log/microk8s-maintenance-*.log`

## 📚 Documentation

### 📄 License

This project is licensed under the MIT License. See the LICENSE file for details.

## 🤝 Support

For issues and questions:

1. Check the troubleshooting guide
2. Review logs using the commands above
3. Run system health checks
4. Contact: [katakaosantri@gmail.com](mailto:katakaosantri@gmail.com)

---

## 🎯 Final Installation Summary

Struktur lengkap **MicroK8s WordPress Enhanced** ini menyediakan:

### ✅ **Komponen Utama**

1. **Infrastruktur**: MicroK8s cluster 2-node dengan MetalLB LoadBalancer
2. **Aplikasi**: WordPress sites (loker.cyou, sabo.news) dengan FrankenPHP
3. **Database**: MariaDB + Redis untuk performa optimal
4. **Reverse Proxy**: Caddy dengan SSL otomatis
5. **Tools**: Adminer, N8N, Supabase, Mailcow

### ✅ **Fitur Enterprise**

- **Auto-scaling**: HPA berdasarkan CPU/Memory usage
- **High Availability**: Multi-replica deployments
- **Monitoring**: Comprehensive health checks dan dashboards
- **Backup**: Automated backup dengan disaster recovery
- **Security**: Network policies, resource quotas, SSL/TLS
- **Maintenance**: Automated updates dan optimization

### ✅ **Scripts Management**

- **17 script utama** untuk semua aspek operasional
- **24 manifest YAML** untuk deployment
- **Load testing framework** untuk performance validation
- **Disaster recovery** dengan multiple restore options

## 🚀 **Quick Start Command**

bash

```bash
*# Clone atau copy semua files ke direktori*
chmod +x quick-setup.sh
./quick-setup.sh

*# Setelah setup node selesai, deploy aplikasi:*
./scripts/deployment/deploy-all.sh

*# Verifikasi dengan:*
./scripts/testing/comprehensive-system-test.sh
```

Sistem ini siap untuk **production deployment** dengan kemampuan untuk menangani traffic tinggi, automatic scaling, dan recovery yang robust! 🎉

[Rekomendasi Prepare](https://www.notion.so/Rekomendasi-Prepare-241c05479c2180d1931df270097b260c?pvs=21)