<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2012, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Go\Core;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use Go\Aop;
use Go\Aop\Pointcut\PointcutLexer;
use Go\Aop\Pointcut\PointcutGrammar;
use Go\Instrument\RawAnnotationReader;

use Dissect\Parser\LALR1\Parser;
use Doctrine\Common\Annotations\AnnotationReader;
use TokenReflection\ReflectionClass as ParsedReflectionClass;

/**
 * Aspect container contains list of all pointcuts and advisors
 */
class AspectWeaver
{
    /**
     * Loader of aspects
     *
     * @var AspectLoader
     */
    protected $loader;

    protected $container;

    public function __construct(AspectLoader $loader, AspectContainer $container)
    {
        $this->loader    = $loader;
        $this->container = $container;
    }

    /**
     * Return list of advices for class
     *
     * @param string|ReflectionClass|ParsedReflectionClass $class Class to advise
     *
     * @return array|Aop\Advice[] List of advices for class
     */
    public function getAdvicesForClass($class)
    {
//        if ($this->loadedResources != $this->resources) {
            $this->loadAdvisorsAndPointcuts();
//        }

        $classAdvices = array();
        if (!$class instanceof ReflectionClass && !$class instanceof ParsedReflectionClass) {
            $class = new ReflectionClass($class);
        }

        $parentClass = $class->getParentClass();

        if ($parentClass && preg_match('/' . AspectContainer::AOP_PROXIED_SUFFIX . '$/', $parentClass->name)) {
            $originalClass = $parentClass;
        } else {
            $originalClass = $class;
        }

        foreach ($this->container->getByTag('advisor') as $advisor) {

            if ($advisor instanceof Aop\PointcutAdvisor) {

                $pointcut = $advisor->getPointcut();
                if ($pointcut->getClassFilter()->matches($class)) {
                    $classAdvices = array_merge_recursive(
                        $classAdvices,
                        $this->getAdvicesFromAdvisor($originalClass, $advisor, $pointcut)
                    );
                }
            }

            if ($advisor instanceof Aop\IntroductionAdvisor) {
                if ($advisor->getClassFilter()->matches($class)) {
                    $classAdvices = array_merge_recursive(
                        $classAdvices,
                        $this->getIntroductionFromAdvisor($originalClass, $advisor)
                    );
                }
            }
        }
        return $classAdvices;
    }

    /**
     * Returns list of advices from advisor and point filter
     *
     * @param ReflectionClass|ParsedReflectionClass $class Class to inject advices
     * @param Aop\PointcutAdvisor $advisor Advisor for class
     * @param Aop\PointFilter $filter Filter for points
     *
     * @return array
     */
    private function getAdvicesFromAdvisor($class, Aop\PointcutAdvisor $advisor, Aop\PointFilter $filter)
    {
        $classAdvices = array();

        // Check methods in class only for method filters
        if ($filter->getKind() & Aop\PointFilter::KIND_METHOD) {

            $mask = ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED;
            foreach ($class->getMethods($mask) as $method) {
                /** @var $method ReflectionMethod| */
                if ($method->getDeclaringClass()->name == $class->name && $filter->matches($method)) {
                    $prefix = $method->isStatic() ? AspectContainer::STATIC_METHOD_PREFIX : AspectContainer::METHOD_PREFIX;
                    $classAdvices[$prefix . ':'. $method->name][] = $advisor->getAdvice();
                }
            }
        }

        // Check properties in class only for property filters
        if ($filter->getKind() & Aop\PointFilter::KIND_PROPERTY) {
            $mask = ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED;
            foreach ($class->getProperties($mask) as $property) {
                /** @var $property ReflectionProperty */
                if ($filter->matches($property)) {
                    $classAdvices[AspectContainer::PROPERTY_PREFIX.':'.$property->name][] = $advisor->getAdvice();
                }
            }
        }

        return $classAdvices;
    }

    /**
     * Returns list of introduction advices from advisor
     *
     * @param ReflectionClass|ParsedReflectionClass $class Class to inject advices
     * @param Aop\IntroductionAdvisor $advisor Advisor for class
     *
     * @return array
     */
    private function getIntroductionFromAdvisor($class, $advisor)
    {
        // Do not make introduction for traits
        if ($class->isTrait()) {
            return array();
        }

        /** @var $advice Aop\IntroductionInfo */
        $advice = $advisor->getAdvice();

        return array(
            AspectContainer::INTRODUCTION_TRAIT_PREFIX.':'.join(':', $advice->getInterfaces()) => $advice
        );
    }

    /**
     * Load pointcuts into container
     *
     * There is no need to always load pointcuts, so we delay loading
     */
    private function loadAdvisorsAndPointcuts()
    {
        // TODO: use a difference with $this->resources to load only missed aspects
        foreach ($this->container->getByTag('aspect') as $aspect) {
            $this->loader->load($aspect);
        }
    }
}
