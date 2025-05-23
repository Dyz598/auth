<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-extension/auth.
 *
 * @link     https://github.com/hyperf-extension/auth
 * @contact  admin@ilover.me
 * @license  https://github.com/hyperf-extension/auth/blob/master/LICENSE
 */
namespace HyperfExtension\Auth\Access;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use HyperfExtension\Auth\Annotations\Policy;
use HyperfExtension\Auth\Contracts\Access\GateManagerInterface;
use HyperfExtension\Auth\Contracts\AuthManagerInterface;
use HyperfExtension\Auth\Events\GateManagerResolved;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use HyperfExtension\Auth\Annotations\Gate as GateAnnotation;

use function Hyperf\Support\call;
use function Hyperf\Support\make;

/**
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface define(string $ability, callable|string $callback)               Define a new ability.
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface resource(string $name, string $class, ?array $abilities = null)  Define abilities for a resource.
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface policy(string $class, string $policy)                            Define a policy class for a given class type.
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface before(callable $callback)                                       Register a callback to run before all Gate checks.
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface after(callable $callback)                                        Register a callback to run after all Gate checks.
 * @method \HyperfExtension\Auth\Contracts\Access\GateInterface forUser(\HyperfExtension\Auth\Contracts\AuthenticatableInterface $user) Get a guard instance for the given user.
 * @method \HyperfExtension\Auth\Access\Response               authorize(string $ability, array|mixed $arguments = [])          Determine if the given ability should be granted for the current user.
 * @method \HyperfExtension\Auth\Access\Response               inspect(string $ability, array|mixed $arguments = [])            Inspect the user for the given ability.
 * @method null|bool|\HyperfExtension\Auth\Access\Response     raw(string $ability, array|mixed $arguments = [])                Get the raw result from the authorization callback.
 * @method mixed                                         getPolicyFor(object|string $class)                               Get a policy instance for a given class.
 * @method bool                                          has(string|string[] $ability)                                    Determine if a given ability has been defined.
 * @method bool                                          allows(string $ability, array|mixed $arguments = [])             Determine if the given ability should be granted for the current user.
 * @method bool                                          denies(string $ability, array|mixed $arguments = [])             Determine if the given ability should be denied for the current user.
 * @method bool                                          check(iterable|string $abilities, array|mixed $arguments = [])   Determine if all of the given abilities should be granted for the current user.
 * @method bool                                          any(iterable|string $abilities, array|mixed $arguments = [])     Determine if any one of the given abilities should be granted for the current user.
 * @method bool                                          none(iterable|string $abilities, array|mixed $arguments = [])    Determine if all of the given abilities should be denied for the current user.
 * @method array                                         abilities()                                                      Get all of the defined abilities.
 */
class GateManager implements GateManagerInterface
{
    /**
     * The container instance.
     *
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * The config instance.
     *
     * @var \Hyperf\Contract\ConfigInterface
     */
    protected $config;

    /**
     * The assess gate instance.
     *
     * @var \Hyperf\Contract\ConfigInterface
     */
    protected $gate;

    /**
     * The event dispatcher instance.
     *
     * @var \Psr\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * The event dispatcher instance.
     *
     * @var \HyperfExtension\Auth\Contracts\AuthManagerInterface
     */
    protected $auth;

    /**
     * Create a new Auth manager instance.
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);
        $this->eventDispatcher = $container->get(EventDispatcherInterface::class);
        $this->auth = $container->get(AuthManagerInterface::class);
        $this->gate = make(Gate::class, ['userResolver' => function () {
            return call($this->auth->userResolver());
        }]);
        $this->registerPoliciesByConfig();
        $this->registerPoliciesByAnnotation();
        $this->registerGateByAnnotation();
        $this->eventDispatcher->dispatch(new GateManagerResolved($this));
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->gate->{$method}(...$parameters);
    }

    /**
     * Register the application's policies by config.
     */
    protected function registerPoliciesByConfig(): void
    {
        $policies = $this->config->get('auth.policies', []);
        foreach ($policies as $model => $policy) {
            $this->gate->policy($model, $policy);
        }
    }

    /**
     * Register the application's policies by annotation.
     */
    protected function registerPoliciesByAnnotation(): void
    {
        $policies = AnnotationCollector::getClassesByAnnotation(Policy::class);
        foreach ($policies as $policy => $annotation) {
            foreach ((array) $annotation->models as $model) {
                $this->gate->policy($model, $policy);
            }
        }
    }

    protected function registerGateByAnnotation(): void
    {
        $gates = AnnotationCollector::getMethodsByAnnotation(GateAnnotation::class);

        foreach ($gates as $metadata) {
            /** @var GateAnnotation $annotation */
            $annotation = $metadata['annotation'];
            $controller = $this->container->get($metadata['class']);

            $this->gate->define($annotation->ability, [$controller, $metadata['method']]);
        }
    }
}
