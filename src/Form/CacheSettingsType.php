<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Form;

use QcdGone\QcdRedis\Cache\RedisConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cache behaviour settings form (activation, TTL, prefix, compression,
 * serializer).
 */
final class CacheSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $igbinary = function_exists('igbinary_serialize');

        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Activation',
                'required' => false,
            ])
            ->add('ttl', IntegerType::class, [
                'label' => 'Default TTL (s, 0 = none)',
                'constraints' => [new Assert\GreaterThanOrEqual(0)],
            ])
            ->add('prefix', TextType::class, [
                'label' => 'Prefix',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 64])],
            ])
            ->add('compression', CheckboxType::class, [
                'label' => 'Compression (gzip)',
                'required' => false,
            ])
            ->add('compression_auto', CheckboxType::class, [
                'label' => 'Automatic compression',
                'required' => false,
            ])
            ->add('compression_threshold', IntegerType::class, [
                'label' => 'Compression threshold (bytes)',
                'constraints' => [new Assert\GreaterThanOrEqual(0)],
            ])
            ->add('flush_on_clear', CheckboxType::class, [
                'label' => 'Also flush Redis when PrestaShop clears its cache',
                'required' => false,
            ])
            ->add('serializer', ChoiceType::class, [
                'label' => 'Serializer',
                'choices' => [
                    'PHP' => RedisConfig::SERIALIZER_PHP,
                    'igbinary' . ($igbinary ? '' : ' (unavailable)') => RedisConfig::SERIALIZER_IGBINARY,
                    'JSON' => RedisConfig::SERIALIZER_JSON,
                ],
                'choice_attr' => static function (string $choice) use ($igbinary): array {
                    return $choice === RedisConfig::SERIALIZER_IGBINARY && !$igbinary
                        ? ['disabled' => 'disabled']
                        : [];
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'Modules.Qcdredis.Admin',
        ]);
    }
}
