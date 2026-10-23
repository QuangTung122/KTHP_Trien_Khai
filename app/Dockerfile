FROM php:8.1-apache

# Cài đặt extension kết nối MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Cấu hình Apache lắng nghe cổng 8080 (vì user non-root không được mở cổng < 1024)
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Phân quyền thư mục cho user www-data
RUN chown -R www-data:www-data /var/www/html /var/run/apache2 /var/lock/apache2 /var/log/apache2

COPY src/ /var/www/html/

# Chuyển sang non-root user
USER www-data
EXPOSE 8080
