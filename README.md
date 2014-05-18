

Gearman PHP ImageWorker
=======================

Introduction
------------
Gearman PHP ImageWorker its a PHP based worker for the [Gearman Job Server][1], it aims to manipulate images asynchronously with different image manipulation tools.

Requirements
------------

 - PHP >=5.4
     - [Gmagick extension][2]
     - [Gearman extension][3]
     - Json Extension
 - [Gearman Job Server][4]
 - GraphicsMagick
 - GIT
 - Composer
 - Currently Amazon S3 Storage service
 - Currently Gmagick

**Recomended**

 - Ubuntu 14.04
 - Supervisor
 - Zend Server 6.3 / PHP 5.5 / Apache 2.4
 - [Gearman UI][5]

Installation
------------
The worker can be installed wherever you want by cloning this repository

```
git clone https://github.com/rumeau/gearman-php-imageworker.git
```

Follow next steps on Ubuntu (Assuming you are on terminal)

```
$ cd ~/
$ git clone https://github.com/rumeau/gearman-php-imageworker.git
$ cd gearman-php-imageworker
$ cp config/config.php.dist config/config.php
(optional) # cp config/aws.php.dist config/aws.php
// Edit both files accordingly
$ chmod 777 log/ tmp/
```

Once completed you can test the worker

```
$ php index.php
```

The worker should stay waiting for a new job

Job Format
----------
The worker waits for a formatted json message to start processing the job. The following PHP script can send a valid message to test the worker

test.php

```php
<?php
$client = new GearmanClient();
$client->addServer();
$client->doBackground("image_server", json_encode(array(
    'filename' => 'my/demo/path/image.jpg', // A test image on Amazon S3
    'task' => 'resize',                     // Only resize is available currently
    'sizes' => array(                       // Array of sizes to generate
        'large' => array(
            'width' => 800,
            'height' => 600
        ),
        'medium' => array(
            'width' => 640,
            'height' => 480
        ),
        'small' => array(
            'width' => 100,
            'height' => 100
        ),
    ),
)));
?>
```
    
Once saved this script to your /home/$USER you can test it

```
~$ php test.php
```
    
This script should send a job to the worker. Based on the worker configuration you can get a verbose output and a profile of time of execution and memory consumed by the worker job.

Extra
-----

**Installing Zend Server 6.3**
Zend provides a free version of Zend Server, download the Zend Server repository from [Zend's Website][6] 

```
$ cd ~/
$ tar xzf ZendServer-6.3.0-RepositoryInstaller-linux.tar.gz
$ cd ZendServer-RepositoryInstaller-linux
$ sudo ./install_zs.sh 5.5
```

This will add Zend's repositories and install Zend Server 6.3 with PHP 5.5 and Apache 2.4 on Ubuntu 14.04

**Installing Gearman Job Server (From sources)**
This will compule and install Gearman 1.1.12 from its sources

```
$ sudo apt-get install -yes gcc autoconf bison flex libtool make libboost-all-dev libcurl4-openssl-dev curl libevent-dev memcached uuid-dev libsqlite3-dev libmysqlclient-dev gperf
$ cd ~/
$ wget https://launchpad.net/gearmand/1.2/1.1.12/+download/gearmand-1.1.12.tar.gz
$ tar xzf gearmand-1.1.12.tar.gz
$ cd gearmand-1.1.12
$ ./configure
$ make
$ sudo install
```
    
**Configuring upstart script**
This took me a while to get it

Update upstart script on /etc/init/gearman-job-server.conf with your parameters

Restart the job server

```
$ sudo service gearman-job-server restart
```
    
**Installing Supervisor**
[TODO]

**Installing GraphicsMagick**

```
$ sudo apt-get install graphicsmagick
```
    
**Installing Gmagick for PHP**
Compiling the Gmagick extension for Zend Server

Once Zend Server is installed you can compile latests extensions with its bundled pecl bin

```
$ sudo apt-get install autoconf graphicsmagick libgraphicsmagick1-dev
$ sudo /usr/local/zend/bin/pecl install gmagick
```
    
Create file /usr/local/zend/etc/conf.d/gmagick.ini and add a line "extension=gmagick.so"

Reload Zend Server

```   
$ sudo /usr/local/zend/bin/zendctl.sh restart
```
    
**Installing Gearman for PHP**

```
$ sudo apt-get install autoconf libgearman-dev
$ sudo /usr/local/zend/bin/pecl install gmagick
```
    
Create file /usr/local/zend/etc/conf.d/gearman.ini and add a line "extension=gearman.so"

Reload Zend Server

```
$ sudo /usr/local/zend/bin/zendctl.sh restart
```

**Installing GIT and Composer**

```
$ sudo apt-get install git
$ cd ~/
$ curl -sS https://getcomposer.org/installer | /usr/local/zend/bin/php
$ sudo mv composer.phar /usr/local/bin/composer
```


  [1]: http://gearman.org/
  [2]: http://pecl.php.net/package/gmagick
  [3]: http://pecl.php.net/package/gearman
  [4]: http://gearman.org/
  [5]: http://gaspaio.github.io/gearmanui/
  [6]: http://www.zend.com/en/products/server/downloads