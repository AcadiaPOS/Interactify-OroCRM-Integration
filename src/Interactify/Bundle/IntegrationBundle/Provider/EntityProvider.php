<?php
namespace Interactify\Bundle\IntegrationBundle\Provider;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Oro\Bundle\ActivityBundle\Manager\ActivityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EntityProvider
{
    /** @var EntityManager */
    protected $em;

    /** @var ContainerInterface */
    protected $config;

    public function __construct(
        EntityManager $em,
        ContainerInterface $config
    ) {
        $this->em = $em;
        $this->config = $config;
    }

    public function getEntities($valueType,$value) 
    {
        $result = [];
        $mappings = [
            'lead_email' => 'oro_sales.lead_email.entity.class',
            'lead_phone' => 'oro_sales.lead_phone.entity.class',
            'contact_email' => 'oro_sales.lead_email.entity.class',
            'contact_phone' => 'oro_sales.lead_phone.entity.class'
        ];
        $entityTypes = ['lead','contact'];
        foreach($entityTypes as $type) {
            if(isset($mappings[$type . '_' . $valueType])) {
                $tmp = $this->config->getParameter($mappings[$type . '_' . $valueType]);
                $repository = $this->em->getRepository($tmp);
                $filterCharacters = [];
                if($valueType == 'phone') {
                    $filterCharacters = ['-',' ','(',')'];
                }
                $generatedCondition = "p." . $valueType;
                foreach($filterCharacters as $filterCharacter) {
                    $generatedCondition = "replace(".$generatedCondition.",'". $filterCharacter . "','')";
                    $value = str_replace($filterCharacter, '', $value);
                }
                $entity = $repository->createQueryBuilder('p')
                    ->where("$generatedCondition=:value")
                    ->setParameter('value', $value)
                    ->getQuery()
                    ->setMaxResults(1)
                    ->getOneOrNullResult();
                if($entity) {
                    $result[$type] = $entity->getOwner();
                }
            }
        }
        return $result;
    }
}
