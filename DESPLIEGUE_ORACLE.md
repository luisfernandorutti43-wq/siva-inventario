# Despliegue de demostración en Oracle Cloud

Esta guía prepara SIVA en una VM Ubuntu para una demostración por Internet, incluido el pronóstico de ventas con Python. No crea una cuenta de Oracle ni de GitHub y no garantiza que Oracle tenga capacidad gratuita disponible en tu región.

## 1. Antes de empezar

- Crea la cuenta de Oracle Cloud desde tu propio equipo. No compartas contraseñas, códigos de verificación, claves SSH ni datos de pago.
- Crea un repositorio en tu GitHub y sube solo los archivos revisados. El archivo `base_datos/siva_inventario.sql` contiene datos operativos reales y no debe publicarse; el archivo `base_datos/siva_inventario_esquema.sql` contiene solo la estructura.
- En Oracle, crea una VM Ubuntu 24.04 LTS. Si está disponible dentro de la cuota gratuita, una instancia Ampere A1 Flex con 1 OCPU y 6 GB de memoria es una opción razonable para PHP y scikit-learn. La capacidad, los límites gratuitos y la verificación de cuenta pueden variar; revisa el costo estimado antes de crear recursos.
- Asigna una IP pública reservada si está disponible y guarda la clave privada SSH únicamente en tu computadora.

## 2. Red de Oracle

En las reglas de entrada de la VCN/NSG o de la lista de seguridad:

| Protocolo | Puerto | Origen |
|---|---:|---|
| TCP | 22 | Solo tu IP pública, en formato `/32` |
| TCP | 80 | `0.0.0.0/0`, para HTTP y emitir el certificado |
| TCP | 443 | `0.0.0.0/0`, para HTTPS |

No abras el puerto 3306 a Internet. MariaDB debe aceptar conexiones solo desde la propia VM.

## 3. Instalar el servidor

Conéctate por SSH usando la clave privada desde tu computadora y ejecuta:

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server git composer \
  php libapache2-mod-php php-cli php-mysql php-mbstring php-xml php-gd php-zip \
  python3 python3-venv python3-pip ufw
sudo a2enmod rewrite env
sudo systemctl enable --now apache2 mariadb
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
```

Si UFW indica que ya estaba activo, confirma que SSH sigue permitido antes de cerrar la sesión.

## 4. Descargar el proyecto y preparar las dependencias

Reemplaza `USUARIO` y `REPOSITORIO` por los valores de tu repositorio público:

```bash
sudo chown "$USER":www-data /var/www
git clone https://github.com/USUARIO/REPOSITORIO.git /var/www/siva-inventario
cd /var/www/siva-inventario
composer install --no-dev --optimize-autoloader
python3 -m venv analisis/.venv
analisis/.venv/bin/python -m pip install --upgrade pip
analisis/.venv/bin/python -m pip install -r requirements.txt
```

Comprueba que PHP tenga las extensiones y funciones necesarias:

```bash
php -r "var_export([extension_loaded('pdo_mysql'), extension_loaded('mbstring'), extension_loaded('phar'), extension_loaded('simplexml'), function_exists('proc_open')]); echo PHP_EOL;"
```

Todos los valores deben ser `true`. `proc_open` es necesario para ejecutar el modelo de pronóstico desde PHP.

## 5. Crear la base de datos vacía

Abre la consola administrativa de MariaDB:

```bash
sudo mariadb
```

Dentro de MariaDB, cambia el marcador por una contraseña larga y única. No reutilices una contraseña personal:

```sql
CREATE DATABASE siva_inventario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'siva_app'@'localhost' IDENTIFIED BY 'REEMPLAZA_CON_UNA_CLAVE_LARGA';
GRANT ALL PRIVILEGES ON siva_inventario.* TO 'siva_app'@'localhost';
EXIT;
```

Importa únicamente el esquema limpio:

```bash
cd /var/www/siva-inventario
mariadb -u siva_app -p siva_inventario < base_datos/siva_inventario_esquema.sql
```

Escribe la contraseña de la base cuando la solicite. Al primer acceso, SIVA ejecuta sus migraciones y crea los tres almacenes. **No importes `base_datos/siva_inventario.sql` en un sitio público.**

## 6. Configurar la conexión privada

Crea el archivo local de configuración desde la plantilla y edítalo:

```bash
cd /var/www/siva-inventario
sudo cp configuracion/hosting.local.example.php configuracion/hosting.local.php
sudo nano configuracion/hosting.local.php
```

Completa los valores de la plantilla con:

```php
return [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'siva_inventario',
    'username' => 'siva_app',
    'password' => 'LA_CLAVE_QUE_CREASTE_EN_MARIADB',
];
```

Protege el archivo para que solo root pueda editarlo y Apache pueda leerlo:

```bash
sudo chown root:www-data configuracion/hosting.local.php
sudo chmod 640 configuracion/hosting.local.php
```

Este archivo está excluido de Git y el directorio `configuracion/` está bloqueado por Apache. Nunca lo publiques.

## 7. Configurar Apache

El proyecto usa su carpeta raíz como `DocumentRoot`; las reglas de `.htaccess` protegen el código y los archivos internos. Crea el sitio:

```bash
sudo nano /etc/apache2/sites-available/siva.conf
```

Pega esta configuración y cambia `demo.example.com` por tu dominio. Si todavía no tienes dominio, usa la IP pública en `ServerName` solo para la prueba inicial:

```apache
<VirtualHost *:80>
    ServerName demo.example.com
    DocumentRoot /var/www/siva-inventario

    <Directory /var/www/siva-inventario>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv SIVA_PYTHON_EXECUTABLE /var/www/siva-inventario/analisis/.venv/bin/python
    ErrorLog ${APACHE_LOG_DIR}/siva-error.log
    CustomLog ${APACHE_LOG_DIR}/siva-access.log combined
</VirtualHost>
```

Activa el sitio y verifica la configuración:

```bash
sudo a2ensite siva.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Da a Apache acceso de lectura al proyecto y permiso de escritura solo al directorio de imágenes de productos:

```bash
sudo chown -R root:www-data /var/www/siva-inventario
sudo find /var/www/siva-inventario -type d -exec chmod 755 {} \;
sudo find /var/www/siva-inventario -type f -exec chmod u=rwX,g=rX,o= {} \;
sudo chown -R www-data:www-data /var/www/siva-inventario/publico/subidas/productos
sudo find /var/www/siva-inventario/publico/subidas/productos -type d -exec chmod 755 {} \;
sudo find /var/www/siva-inventario/publico/subidas/productos -type f -exec chmod 644 {} \;
```

## 8. Activar HTTPS

Apunta el registro DNS `A` de tu dominio a la IP pública de la VM y espera a que se propague. Luego ejecuta:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d demo.example.com
```

Reemplaza el dominio de ejemplo. Para presentar un inicio de sesión por Internet, utiliza HTTPS y una cuenta de demostración con una contraseña exclusiva. No reutilices contraseñas personales ni las de la empresa.

## 9. Primer inicio y prueba del pronóstico

1. Abre `https://TU-DOMINIO/vistas/index.php` y crea el primer administrador. La base está vacía; no hay credenciales predeterminadas.
2. En **Productos**, crea el producto de demostración con SKU `DEMO-175-70R13`, marca `DEMO`, medida `175/70R13` y modelo `DEMO-ROAD`. Asígnale existencias ficticias en A0101.
3. En **Inventario → Pronóstico de ventas**, importa los tres archivos de `demostracion/` en orden: septiembre, octubre y noviembre de 2026. Revisa cada vista previa antes de confirmar. Son ventas ficticias para un solo SKU; no las mezcles con ventas reales ni registres las mismas fechas como salidas.
4. Vuelve al pronóstico y comprueba que aparezca la estimación del producto DEMO. Los archivos son sintéticos y solo sirven para verificar el flujo; no representan una predicción de ventas real.

## 10. Lista de comprobación antes de compartir el enlace

- El sitio abre con HTTPS y el inicio de sesión permite crear y usar una cuenta de demostración.
- El panel muestra los almacenes A0101, A0102 y A0103.
- El pronóstico funciona después de importar los archivos sintéticos.
- Las reglas de red no exponen SSH a todo Internet ni MariaDB en el puerto 3306.
- No subiste bases de datos reales, Excel de ventas, archivos de configuración privada, presentaciones ni claves SSH al repositorio público.
- Conserva una copia privada de la configuración y prepara una copia de seguridad de la base de demostración.
