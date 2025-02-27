<?php

namespace EasyCouponManager\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class BackHook extends BaseHook
{
    /**
     * @param HookRenderEvent $event
     * @return void
     */
    public function onMainInTopMenuItems(HookRenderEvent $event)
    {
        $event->add(
            $this->render('EasyCouponManager/hook/main.in.top.menu.items.html', [])
        );
    }
}
