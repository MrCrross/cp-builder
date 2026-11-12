<script module lang="ts">
    import { edit } from '@/routes/profile';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';

    const user = $derived(page.props.auth.user);
</script>

<AppHead title="Профиль" />

<h1 class="sr-only">Профиль</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Профиль"
        description="Имя и email"
    />

    <Form
        {...ProfileController.update.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Имя</Label>
                <Input
                    id="name"
                    name="name"
                    class="mt-1 block w-full"
                    value={user.name}
                    required
                    autocomplete="name"
                    placeholder="Имя"
                />
                <InputError class="mt-2" message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    class="mt-1 block w-full"
                    value={user.email}
                    required
                    autocomplete="username"
                    placeholder="Email"
                />
                <InputError class="mt-2" message={errors.email} />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="update-profile-button">Сохранить</Button
                >
            </div>
        {/snippet}
    </Form>
</div>
