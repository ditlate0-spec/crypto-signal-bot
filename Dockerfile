# ============================================
# PHP 8.2 + Apache + Python 3.11
# ============================================
FROM php:8.2-apache

# 1. Системные пакеты
RUN apt-get update && apt-get install -y \
    wget \
    curl \
    git \
    unzip \
    build-essential \
    libssl-dev \
    zlib1g-dev \
    libncurses5-dev \
    libncursesw5-dev \
    libreadline-dev \
    libsqlite3-dev \
    libgdbm-dev \
    libdb5.3-dev \
    libbz2-dev \
    libexpat1-dev \
    liblzma-dev \
    tk-dev \
    libffi-dev \
    libcurl4-openssl-dev \
    libzip-dev \
    && docker-php-ext-install mysqli curl zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Ставим Python 3.11 из исходников
RUN cd /tmp && \
    wget https://www.python.org/ftp/python/3.11.9/Python-3.11.9.tgz && \
    tar xzf Python-3.11.9.tgz && \
    cd Python-3.11.9 && \
    ./configure --enable-optimizations && \
    make -j$(nproc) && \
    make altinstall && \
    rm -rf /tmp/Python-3.11.9*

# 3. Делаем python3.11 = python3
RUN ln -sf /usr/local/bin/python3.11 /usr/bin/python3 && \
    ln -sf /usr/local/bin/pip3.11 /usr/bin/pip3

# 4. Обновляем pip
RUN python3 -m pip install --upgrade pip setuptools wheel

# 5. Python-библиотеки
COPY Kronos-master/requirements.txt /tmp/requirements.txt
RUN python3 -m pip install --no-cache-dir -r /tmp/requirements.txt

# 6. Apache mod_rewrite
RUN a2enmod rewrite

# 7. Рабочая директория
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80