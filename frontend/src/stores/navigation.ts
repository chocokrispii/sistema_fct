import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useNavigationStore = defineStore('navigation', () => {
    const activeTab = ref('principal');
    const currentRole = ref('anonymous');

    function storageKey(role: string): string {
        return `fct.active-tab.${role.toLowerCase()}`;
    }

    function initialize(role: string, availableTabs: string[]): void {
        currentRole.value = role || 'anonymous';
        const fromUrl = new URLSearchParams(window.location.search).get('tab');
        const fromStorage = window.localStorage.getItem(storageKey(currentRole.value));
        const selected = fromUrl || fromStorage || availableTabs[0] || 'principal';
        activeTab.value = availableTabs.includes(selected) ? selected : (availableTabs[0] || 'principal');
        syncUrl();
    }

    function selectTab(tab: string): void {
        activeTab.value = tab;
        window.localStorage.setItem(storageKey(currentRole.value), tab);
        syncUrl();
    }

    function syncUrl(): void {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', activeTab.value);
        window.history.replaceState({}, '', url);
    }

    return { activeTab, currentRole, initialize, selectTab };
});
