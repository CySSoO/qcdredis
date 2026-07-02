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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Redis connection settings form.
 *
 * The password field is never pre-filled and is only persisted when a value is
 * submitted, so an empty submission preserves the stored password.
 */
final class ConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, [
                'label' => 'IP / Host',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('port', IntegerType::class, [
                'label' => 'Port',
                'constraints' => [new Assert\Range(['min' => 1, 'max' => 65535])],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Password',
                'required' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'Leave blank to keep the current password.',
            ])
            ->add('db', IntegerType::class, [
                'label' => 'Database',
                'constraints' => [new Assert\GreaterThanOrEqual(0)],
            ])
            ->add('timeout', NumberType::class, [
                'label' => 'Timeout (s)',
                'scale' => 2,
                'constraints' => [new Assert\Positive()],
            ])
            ->add('tls', CheckboxType::class, [
                'label' => 'TLS (future)',
                'required' => false,
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
