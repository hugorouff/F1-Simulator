<?php
namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel 
{ 
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if ($this->environment === 'prod') {
            return '/tmp/var/cache/'.$this->environment;
        }
        return dirname(__DIR__).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        if ($this->environment === 'prod') {
            return '/tmp/var/log';
        }
        return dirname(__DIR__).'/var/log';
    }
}
