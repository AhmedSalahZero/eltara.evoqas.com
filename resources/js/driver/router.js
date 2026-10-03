// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App screens (addresses under /driver/)
//  Location: resources/js/driver/router.js
//  Without a saved sign-in every screen opens the sign-in screen.
//  Step 4: trip details and its forms (expense, cash from the client,
//  use client money, receive custody, custody ran out, confirm delivery). meta.tab keeps the
//  right tab lit in the bottom menu while a form is open.
// ══════════════════════════════════════════════════════════════════

import { createRouter, createWebHistory } from 'vue-router';
import { state } from './store';

import Login from './screens/Login.vue';
import Home from './screens/Home.vue';
import Trips from './screens/Trips.vue';
import Wallets from './screens/Wallets.vue';
import Alerts from './screens/Alerts.vue';
import Account from './screens/Account.vue';
import TripDetail from './screens/TripDetail.vue';
import AddExpense from './screens/AddExpense.vue';
import CashFromClient from './screens/CashFromClient.vue';
import UseClientMoney from './screens/UseClientMoney.vue';
import ConfirmDelivery from './screens/ConfirmDelivery.vue';
import ReceiveCustody from './screens/ReceiveCustody.vue';
import RequestCustody from './screens/RequestCustody.vue';

export const router = createRouter({
    history: createWebHistory('/driver/'),
    routes: [
        { path: '/login', name: 'login', component: Login, meta: { guest: true } },
        { path: '/', name: 'home', component: Home },
        { path: '/trips', name: 'trips', component: Trips },
        { path: '/trips/:id(\\d+)', name: 'trip', component: TripDetail, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/expense', name: 'trip-expense', component: AddExpense, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/cash', name: 'trip-cash', component: CashFromClient, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/transfer', name: 'trip-transfer', component: UseClientMoney, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/deliver', name: 'trip-deliver', component: ConfirmDelivery, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/custody', name: 'trip-custody', component: ReceiveCustody, meta: { tab: 'trips' } },
        { path: '/trips/:id(\\d+)/custody-request', name: 'trip-custody-request', component: RequestCustody, meta: { tab: 'trips' } },
        { path: '/wallets', name: 'wallets', component: Wallets },
        { path: '/alerts', name: 'alerts', component: Alerts },
        { path: '/account', name: 'account', component: Account },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach((to) => {
    if (!state.profile && !to.meta.guest) return { name: 'login' };
    if (state.profile && to.meta.guest) return { name: 'home' };
    return true;
});
