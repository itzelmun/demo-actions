FROM php:7.4.30

RUN mkdir -p /var/www/simplesaml_funcionando 
# crear carpeta simplesaml dentro de /var/www/html
COPY ./simplesaml_funcionando/  /var/www/simplesaml_funcionando/
COPY ./simplesaml_funcionando/000-default.conf  /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite 
RUN service apache2 restart

EXPOSE 80
