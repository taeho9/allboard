FROM php:8.3-fpm

RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN apt-get update && apt-get install -y openssh-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# GitHub repo에서 클론된 html 파일을 임시 위치에 복사했다고 가정
# (Portainer가 클론한 경로에 맞게 조정 필요)

COPY /html/ /bootstrap-html/

# 복사 스크립트를 실행하는 entrypoint 스크립트 추가
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

EXPOSE 9000
CMD ["php-fpm"]