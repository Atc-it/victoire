<?php
namespace Victoire\Bundle\I18nBundle\CacheWarmer;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmer;
use Behat\Behat\Exception\Exception;

/**
 *
 * @author Florian Raux
 *
 */
class I18nWarmer extends CacheWarmer
{
    private $annotationReader;

    /**
     * Constructor
     *
     * @param unknown $annotationReader
     */
    public function __construct($annotationReader)
    {
        $this->annotationReader = $annotationReader;
    }

    /**
     * Warms up the cache.
     *
     * @param string $cacheDir The cache directory
     *
     * @throws Exception
     */
    public function warmUp($cacheDir)
    {
        $dir = $cacheDir.'/victoire/Entity';
        $file = $dir.'/I18n.php';

        if (!file_exists($dir)) {
            if (false === @mkdir($dir, 0777, true)) {
                throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
            }
        }

        $generator = new EntityProxyGenerator($this->annotationReader);
        $generator->setSkeletonDirs(__DIR__."/skeleton/");
        $cacheContent = $generator->generate();

        $this->writeCacheFile($file, $cacheContent);
        if (!class_exists("Victoire\Bundle\I18nBundle\Entity\I18n")) {
            include_once $file;
        }
    }

    /**
     * IS the warmer optionnal
     *
     * @return boolean
     */
    public function isOptional()
    {
        return false;
    }
}
