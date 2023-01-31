#Plugin de Magento

ONVO cuenta con un plugin para páginas web desarrolladas con Magento. 
Seguí los siguientes pasos para habilitar pagos a través de ONVO Pay en tu e-commerce:

##Prerrequisitos

- **Versión de Magento:** 2.4.3 o mayor.
- **Versión de PHP:** 7.1 o mayor.

##Instalación

1. Via composer:
   1. Desde la consola ir al 'root' del proyecto de magento
   2. Ejecutar: `composer require logeek-io/onvo-magento`


2. Via directorio
   1. Descargá el plugin y subilo al directorio /app/code/ONVO/


3. Correr comandos de magento:

`bin/magento setup:upgrade`

`bin/magento setup:di:compile`

4. Ir al admin de magento 'Stores -> Configuration -> Sales -> Payment Methods -> ONVO Pay'


5. Configurar public y secret key
