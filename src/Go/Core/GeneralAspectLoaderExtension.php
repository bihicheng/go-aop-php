<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2012, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Go\Core;

use ReflectionMethod;
use ReflectionProperty;

use Go\Aop\Aspect;
use Go\Aop\Framework;
use Go\Aop\Pointcut;
use Go\Aop\PointFilter;
use Go\Aop\Support;
use Go\Aop\Support\DefaultPointcutAdvisor;
use Go\Lang\Annotation;

use Dissect\Lexer\Exception\RecognitionException;
use Dissect\Parser\Exception\UnexpectedTokenException;

/**
 * General aspect loader add common support for general advices, declared as annotations
 */
class GeneralAspectLoaderExtension implements AspectLoaderExtension
{

    /**
     * General aspect loader works with annotations from aspect
     *
     * For extension that works with annotations additional metaInformation will be passed
     *
     * @return string
     */
    public function getKind()
    {
        return self::KIND_ANNOTATION;
    }

    /**
     * General aspect loader works only with methods of aspect
     *
     * @return string|array
     */
    public function getTarget()
    {
        return self::TARGET_METHOD;
    }

    /**
     * Checks if loader is able to handle specific point of aspect
     *
     * @param Aspect $aspect Instance of aspect
     * @param mixed|\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection Reflection of point
     * @param mixed|null $metaInformation Additional meta-information, e.g. annotation for method
     *
     * @return boolean true if extension is able to create an advisor from reflection and metaInformation
     */
    public function supports(Aspect $aspect, $reflection, $metaInformation = null)
    {
        return $metaInformation instanceof Annotation\Interceptor
                || $metaInformation instanceof Annotation\Pointcut;
    }

    /**
     * Loads definition from specific point of aspect into the container
     *
     * @param AspectContainer $container Instance of container
     * @param Aspect $aspect Instance of aspect
     * @param mixed|\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection Reflection of point
     * @param mixed|null $metaInformation Additional meta-information, e.g. annotation for method
     */
    public function load(AspectContainer $container, Aspect $aspect, $reflection, $metaInformation = null)
    {
        /** @var $pointcut Pointcut|PointFilter */
        $pointcut       = $this->parsePointcut($container, $reflection, $metaInformation);
        $methodId       = sprintf("%s->%s", $reflection->class, $reflection->name);
        $adviceCallback = Framework\BaseAdvice::fromAspectReflection($aspect, $reflection);

        if (isset($metaInformation->scope) && $metaInformation->scope !== 'aspect') {
            $scope = $metaInformation->scope;
            $adviceCallback = Framework\BaseAdvice::createScopeCallback($adviceCallback, $scope);
        }

        $isPointFilter  = $pointcut instanceof PointFilter;
        switch (true) {
            // Register a pointcut by its name
            case ($metaInformation instanceof Annotation\Pointcut):
                $container->registerPointcut($pointcut, $methodId);
                break;

            case ($isPointFilter && ($pointcut->getKind() & PointFilter::KIND_METHOD)):
                $advice = $this->getMethodInterceptor($metaInformation, $adviceCallback);
                if ($pointcut instanceof Support\DynamicMethodMatcher) {
                    $advice = new Framework\DynamicMethodMatcherInterceptor(
                        $pointcut,
                        $advice
                    );
                }
                $container->registerAdvisor(new DefaultPointcutAdvisor($pointcut, $advice), $methodId);
                break;

            case ($isPointFilter && ($pointcut->getKind() & PointFilter::KIND_PROPERTY)):
                $advice = $this->getPropertyInterceptor($metaInformation, $adviceCallback);
                $container->registerAdvisor(new DefaultPointcutAdvisor($pointcut, $advice), $methodId);
                break;

            default:
                throw new \UnexpectedValueException("Unsupported pointcut class: " . get_class($pointcut));
        }
    }

    /**
     * @param $metaInformation
     * @param $adviceCallback
     * @return \Go\Aop\Intercept\MethodInterceptor
     * @throws \UnexpectedValueException
     */
    protected function getMethodInterceptor($metaInformation, $adviceCallback)
    {
        switch (true) {
            case ($metaInformation instanceof Annotation\Before):
                return new Framework\MethodBeforeInterceptor($adviceCallback, $metaInformation->order);

            case ($metaInformation instanceof Annotation\After):
                return new Framework\MethodAfterInterceptor($adviceCallback, $metaInformation->order);

            case ($metaInformation instanceof Annotation\Around):
                return new Framework\MethodAroundInterceptor($adviceCallback, $metaInformation->order);

            case ($metaInformation instanceof Annotation\AfterThrowing):
                return new Framework\MethodAfterThrowingInterceptor($adviceCallback, $metaInformation->order);

            default:
                throw new \UnexpectedValueException("Unsupported method meta class: " . get_class($metaInformation));
        }
    }

    /**
     * @param $metaInformation
     * @param $adviceCallback
     * @return \Go\Aop\Intercept\FieldAccess
     * @throws \UnexpectedValueException
     */
    protected function getPropertyInterceptor($metaInformation, $adviceCallback)
    {
        switch (true) {
            case ($metaInformation instanceof Annotation\Before):
                return new Framework\FieldBeforeInterceptor($adviceCallback, $metaInformation->order);

            case ($metaInformation instanceof Annotation\After):
                return new Framework\FieldAfterInterceptor($adviceCallback, $metaInformation->order);

            case ($metaInformation instanceof Annotation\Around):
                return new Framework\FieldAroundInterceptor($adviceCallback, $metaInformation->order);

            default:
                throw new \UnexpectedValueException("Unsupported method meta class: " . get_class($metaInformation));
        }
    }

    /**
     * Temporary method for parsing pointcuts
     *
     * @param AspectContainer $container Container
     * @param Annotation\BaseAnnotation|Annotation\BaseInterceptor $metaInformation
     * @param mixed|\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection Reflection of point
     *
     * @throws \UnexpectedValueException if there was an error during parsing
     * @return \Go\Aop\Pointcut
     */
    private function parsePointcut(AspectContainer $container, $reflection, $metaInformation)
    {
        /** @var $lexer \Dissect\Lexer\Lexer */
        $lexer  = $container->get('aspect.pointcut.lexer');
        try {
            $stream = $lexer->lex($metaInformation->value);
        } catch (RecognitionException $e) {
            $message = "Can not recognize the lexical structure `%s` before %s, defined in %s:%d";
            $message = sprintf(
                $message,
                $metaInformation->value,
                (isset($reflection->class) ? $reflection->class . '->' : '') . $reflection->name,
                $reflection->getFileName(),
                $reflection->getStartLine()
            );
            throw new \UnexpectedValueException($message, 0, $e);
        }

        /** @var $parser \Dissect\Parser\Parser */
        $parser = $container->get('aspect.pointcut.parser');
        try {
            return $parser->parse($stream);
        } catch (UnexpectedTokenException $e) {
            /** @var \Dissect\Lexer\Token $token */
            $token    = $e->getToken();
            $message  = "Unexpected token %s in the `%s` before %s, defined in %s:%d." . PHP_EOL;
            $message .= "Expected one of: %s";
            $message  = sprintf(
                $message,
                $token->getValue(),
                $metaInformation->value,
                (isset($reflection->class) ? $reflection->class . '->' : '') . $reflection->name,
                $reflection->getFileName(),
                $reflection->getStartLine(),
                join(', ', $e->getExpected())
            );
            throw new \UnexpectedValueException($message, 0, $e);
        }
    }
}