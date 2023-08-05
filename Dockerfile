FROM archlinux

ENV SHELL=/bin/zsh
ENV TERM=xterm-256color

RUN echo "[multilib]" >> /etc/pacman.conf && \
    echo "Include = /etc/pacman.d/mirrorlist" >> /etc/pacman.conf && \
    sed -i "s/#MAKEFLAGS=\"-j2\"/MAKEFLAGS=\"-j$(nproc)\"/" /etc/makepkg.conf

RUN pacman --noconfirm -Syu \
    git \
    neovim \
    tmux \
    zsh

RUN chsh -s /bin/zsh root && \
    mkdir ~/.zsh.local && \
    echo -e "alias vim=nvim\nalias sudo='sudo '" > ~/.zsh.local/aliases.zsh && \
    curl -Lo- http://bit.ly/2pztvLf | bash

RUN pacman --noconfirm -Syu \
    autoconf \
    bc \
    binutils \
    bwm-ng \
    composer \
    fakeroot \
    ffmpeg \
    gcc \
    htop \
    jq \
    lame \
    make \
    mariadb-clients \
    mariadb \
    mediainfo \
    mytop \
    nginx \
    nmon \
    p7zip \
    php-fpm \
    php-gd \
    php-intl \
    powerline-fonts \
    python-neovim \
    sudo \
    supervisor \
    the_silver_searcher \
    time \
    unrar \
    unzip \
    vnstat \
    wget \
    which \
    yarn

RUN wget http://pear.php.net/go-pear.phar -O /tmp/go-pear.phar && \
    php /tmp/go-pear.phar && \
    rm /tmp/go-pear.phar

RUN mkdir -p /var/log/php && \
    ln -sf /dev/stderr /var/log/php/php-fpm.error.log && \
    ln -sf /dev/stdout /var/log/php/php-fpm.access.log

RUN sed -i  \
        -e "s/user = http/user = nntmux/" \
        -e "s/group = http/group = nntmux/" \
        -e "s#;access.log = log/\$pool.access.log#access.log = /var/log/php/php-fpm.access.log#" \
        /etc/php/php-fpm.d/www.conf && \
    sed -i "s#error_log = syslog#error_log = /var/log/php/php-fpm.error.log#" /etc/php/php-fpm.conf && \
    sed -i \
        -e 's/^extension=curl/;extension=curl/' \
        -e 's/^extension=zip/;extension=zip/' \
        /etc/php/php.ini

RUN pecl channel-update pecl.php.net && \
    pecl install zip

COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/supervisor.d /etc/supervisor.d
COPY ./docker/php-overrides.ini /etc/php/conf.d/overrides.ini
COPY ./docker/php-overrides.local.ini /etc/php/conf.d/overrides.local.ini

RUN groupadd --gid 1000 nntmux && \
    useradd --create-home --system --shell /usr/sbin/zsh --uid 1000 --gid 1000 nntmux && \
    passwd -d nntmux && \
    chsh -s /bin/zsh nntmux && \
    echo 'nntmux ALL=(ALL) ALL' > /etc/sudoers.d/nntmux && \
    mkdir -p /home/nntmux/.gnupg && \
    echo 'standard-resolver' > /home/nntmux/.gnupg/dirmngr.conf && \
    chown -R nntmux:nntmux /home/nntmux

USER nntmux

# COPY ./docker/composer-auth.json /home/nntmux/.composer/auth.json
# COPY ./docker/.mytop /home/nntmux/.mytop

RUN git clone --depth 1 https://aur.archlinux.org/yay.git /tmp/yay && \
    cd /tmp/yay && \
    makepkg --noconfirm -si && \
    yay --afterclean --removemake --save && \
    rm -rf /tmp/yay

RUN yay --noconfirm -Sy \
    php-imagick \
    php-memcached \
    php-sodium
    # \ tcptrack

RUN mkdir ~/.zsh.local && \
    echo -e "alias vim=nvim\nalias sudo='sudo '" > ~/.zsh.local/aliases.zsh && \
    curl -Lo- http://bit.ly/2pztvLf | bash

WORKDIR /site

RUN git clone --depth 1 https://github.com/egyptianbman/newznab-tmux.git . && \
    composer install --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist

# RUN sudo pacman --noconfirm -Sy openssh

CMD ["sudo", "--preserve-env", "/usr/sbin/supervisord", "--nodaemon"]
