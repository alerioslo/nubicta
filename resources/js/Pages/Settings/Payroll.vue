<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ settings: Object, operators: Array, hours: Object, tab: String });

const form = useForm({ ...props.settings });
const active = ref(props.tab);
const tabs = { liquidacion: 'Liquidación de nómina', grupos: 'Grupo de nómina', certificado: 'Certificado laboral', ley: 'Datos de ley' };
const newGroup = ref('');

function addGroup() {
    const g = newGroup.value.trim();
    if (g && !form.payroll_groups.includes(g)) form.payroll_groups.push(g);
    newGroup.value = '';
}

const submit = () => form.put('/configuracion/nomina', { preserveScroll: true });
</script>

<template>
    <Head title="Configuración general de nómina" />
    <h1 class="pt-[10px] text-[24px] font-semibold text-ink">Configuración general de nómina</h1>
    <nav class="mt-[14px] flex gap-[4px] border-b border-line">
        <button v-for="(label, k) in tabs" :key="k" type="button" class="px-[12px] pb-[10px] text-[13px]"
            :class="active === k ? 'border-b-2 border-primary font-semibold text-primary' : 'text-ink'" @click="active = k">{{ label }}</button>
    </nav>

    <form class="mt-[14px] space-y-[14px] text-[13px]" @submit.prevent="submit">
        <template v-if="active === 'liquidacion'">
            <section class="s-card">
                <h3 class="border-b border-[#edf0f2] px-[22px] py-[14px] text-[16px] font-semibold text-ink">Configuración de empresa</h3>
                <div class="grid grid-cols-2 gap-x-[24px] px-[22px] py-[16px]">
                    <label class="block">
                        <span class="text-[12px] text-muted">Operador de información PILA*</span>
                        <select v-model="form.pila_operator" class="s-input mt-[4px] w-full">
                            <option :value="null">Selecciona una opción</option>
                            <option v-for="o in operators" :key="o" :value="o">{{ o }}</option>
                        </select>
                        <p v-if="form.errors.pila_operator" class="s-error">{{ form.errors.pila_operator }}</p>
                    </label>
                </div>
                <div class="space-y-[14px] px-[22px] pb-[20px]">
                    <p>Selecciona la configuración de la nómina de tu empresa:</p>
                    <div v-for="(label, k) in { decimals: 'Maneja nómina con decimales.', exempt_health_parafiscal: 'Es exonerada de salud y parafiscales.', parafiscal_reduction: 'Es beneficiaria de reducción de pagos de parafiscales.' }"
                        :key="k" class="flex items-center gap-[10px]">
                        <label class="s-switch"><input v-model="form[k]" type="checkbox" /><span /></label> {{ label }}
                    </div>
                </div>
            </section>
            <div class="grid grid-cols-2 gap-[14px]">
                <section class="s-card">
                    <h3 class="border-b border-[#edf0f2] px-[22px] py-[14px] text-[16px] font-semibold text-ink">Horas laborales</h3>
                    <div class="space-y-[10px] px-[22px] py-[16px]">
                        <p class="rounded border border-[#bfe0fb] bg-[#e8f4fe] px-[12px] py-[10px]">Según la <b>ley 2101 del 2021</b>, la jornada laboral se reduce gradualmente hasta llegar a 42 horas semanales.</p>
                        <p>Selecciona la cantidad de horas mensuales que aplican de ahora en adelante para el cálculo de horas extras y recargos en los procesos de nómina, liquidación de contrato y PILA.</p>
                        <label v-for="(label, h) in hours" :key="h" class="flex items-center gap-[8px]">
                            <input v-model.number="form.monthly_hours" type="radio" :value="Number(h)" class="accent-primary" /> {{ label }}
                        </label>
                    </div>
                </section>
                <section class="s-card">
                    <h3 class="border-b border-[#edf0f2] px-[22px] py-[14px] text-[16px] font-semibold text-ink">Retención en la fuente</h3>
                    <div class="space-y-[10px] px-[22px] py-[16px]">
                        <p>De acuerdo con la normativa, las tasas en UVT de la retención en la fuente de los pagos laborales fueron modificadas. Deberás seleccionar un modo de manejo de las tasas y topes para el cálculo de las nóminas y liquidaciones de contrato.</p>
                        <label class="flex items-center gap-[8px]"><input v-model="form.uvt_mode" type="radio" value="promedio" class="accent-primary" /> Promedio mensual de UVT: Total del UVT anual dividido en 12 meses</label>
                        <label class="flex items-center gap-[8px]"><input v-model="form.uvt_mode" type="radio" value="acumulado" class="accent-primary" /> Acumulado de UVT: Control mensual hasta la cantidad permitida de UVT</label>
                    </div>
                </section>
            </div>
        </template>

        <section v-else-if="active === 'grupos'" class="s-card px-[22px] py-[16px]">
            <h3 class="mb-[10px] text-[16px] font-semibold text-ink">Grupos de nómina</h3>
            <p class="mb-[10px] text-muted">Los grupos determinan las cuentas contables con las que se contabiliza la nómina (ver Parámetros contables de nómina).</p>
            <ul class="mb-[12px] space-y-[6px]">
                <li v-for="(g, i) in form.payroll_groups" :key="g" class="flex w-[420px] items-center justify-between border-b border-[#edf0f2] py-[4px]">
                    {{ g }} <button type="button" class="text-muted hover:text-danger" @click="form.payroll_groups.splice(i, 1)"><Icon icon="xmark" /></button>
                </li>
            </ul>
            <div class="flex gap-[8px]"><input v-model="newGroup" class="s-input w-[320px]" placeholder="Nuevo grupo" @keydown.enter.prevent="addGroup" /><button type="button" class="s-btn-outline" @click="addGroup">Agregar</button></div>
        </section>

        <section v-else-if="active === 'certificado'" class="s-card space-y-[12px] px-[22px] py-[16px]">
            <h3 class="text-[16px] font-semibold text-ink">Certificado laboral</h3>
            <label class="block w-[520px]"><span class="text-[12px] text-muted">Nombre de quien firma</span><input v-model="form.certificate_signer" class="s-input mt-[4px] w-full" /></label>
            <label class="block w-[520px]"><span class="text-[12px] text-muted">Cargo de quien firma</span><input v-model="form.certificate_signer_position" class="s-input mt-[4px] w-full" /></label>
            <label class="block"><span class="text-[12px] text-muted">Texto adicional del certificado</span><textarea v-model="form.certificate_text" rows="5" class="s-input mt-[4px] w-full" /></label>
        </section>

        <section v-else class="s-card px-[22px] py-[16px]">
            <h3 class="mb-[12px] text-[16px] font-semibold text-ink">Datos de ley</h3>
            <div class="grid w-[640px] grid-cols-2 gap-[12px]">
                <label class="block"><span class="text-[12px] text-muted">Año</span><input v-model.number="form.year" type="number" class="s-input mt-[4px] w-full" /></label>
                <label class="block"><span class="text-[12px] text-muted">Salario mínimo mensual legal vigente</span><input v-model.number="form.minimum_wage" type="number" class="s-input mt-[4px] w-full" /></label>
                <label class="block"><span class="text-[12px] text-muted">Auxilio de transporte</span><input v-model.number="form.transport_aid" type="number" class="s-input mt-[4px] w-full" /></label>
                <label class="block"><span class="text-[12px] text-muted">Valor UVT</span><input v-model.number="form.uvt" type="number" class="s-input mt-[4px] w-full" /></label>
            </div>
        </section>

        <div class="flex justify-end"><button type="submit" class="s-btn-primary" :disabled="form.processing">Guardar cambios</button></div>
    </form>
</template>
