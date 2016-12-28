<?php
namespace Interactify\Bundle\IntegrationBundle\Provider;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Oro\Bundle\ActivityBundle\Manager\ActivityManager;

class ActivityProvider
{
    /** @var ActivityManager */
    protected $activityManager;

    /** @var EntityManager */
    protected $em;

    public function __construct(
        ActivityManager $activityManager,
        EntityManager $manager
    ) {
        $this->activityManager = $activityManager;
        $this->em = $manager;
    }

    public function getActivities($entity) {
        $entityClass = ClassUtils::getClass($entity);
        $items = $this->activityManager->getActivityAssociations($entityClass);
        $result = [];
        foreach($items as $item) {
            $qb = $this->em->getRepository($item['className'])->createQueryBuilder('e');
            $this->activityManager->addFilterByTargetEntity($qb,$entityClass,$entity->getId());
            $result[$item['className']] = $qb->getQuery()->getArrayResult();
        }
        return $result;
    }
}
