<header class="bg-white dark:bg-neutral-800 shadow">
   <div class="flex justify-between max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
       <div>
           <h2 class="font-semibold text-xl text-neutral-800 dark:text-neutral-200 leading-tight">
               <x-link href="{{ route('insights.pds.manage.index') }}" class="inline-block px-3 py-6" wire:navigate><i
                       class="icon-arrow-left"></i></x-link><span class="ml-4"><span class="hidden sm:inline">{{ __('Sistem dossing pH') }}</span><span class="sm:hidden inline">{{ __('PDS') }}</span></span>
           </h2>
       </div>
   </div>
</header>