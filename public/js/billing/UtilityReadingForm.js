/**
 * UtilityReadingForm.js – Vue 3 Composition API (ESM Module)
 *
 * Component hiển thị bảng nhập chỉ số điện/nước cho chủ trọ.
 *
 * Luồng dữ liệu:
 *   1. Load → gọi fetchRooms() với tháng/năm hiện tại
 *   2. Hiển thị danh sách phòng có hợp đồng active
 *   3. Mỗi phòng có 2 input (điện, nước) + upload ảnh
 *   4. Bấm "Lưu" → POST /api/landlord/utility-readings
 *   5. Xử lý lỗi 422 (validation) inline trên từng input
 *
 * Lưu ý API:
 *   - Dùng multipart/form-data (FormData) vì có upload file
 *   - Auth: Cookie session (không cần token thủ công trong SPA mode)
 *   - CSRF: Axios đã tự đọc XSRF-TOKEN cookie nhờ bootstrap.js
 */

import { ref, reactive, computed, onMounted } from 'vue';

// ── Axios instance wrapper ────────────────────────────────────────────────────
// window.axios được setup bởi resources/js/bootstrap.js (X-Requested-With header)
const http = window.axios.create({
    baseURL: window.__BILLING_CONFIG__?.apiBase ?? '/api',
    headers: {
        'X-CSRF-TOKEN': window.__BILLING_CONFIG__?.csrfToken ?? '',
    },
    withCredentials: true, // Gửi session cookie theo request
});

// ── Helper: format số VND ─────────────────────────────────────────────────────
const formatVnd = (n) =>
    new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ';

// ══════════════════════════════════════════════════════════════════════════════
// COMPONENT
// ══════════════════════════════════════════════════════════════════════════════
export default {
    name: 'UtilityReadingForm',

    setup() {
        // ── Config từ Blade ───────────────────────────────────────────────
        const config = window.__BILLING_CONFIG__ ?? {};

        // ── State: Tháng/năm đang xem ─────────────────────────────────────
        const selectedMonth = ref(config.currentMonth ?? new Date().getMonth() + 1);
        const selectedYear  = ref(config.currentYear  ?? new Date().getFullYear());

        // ── State: Danh sách phòng ────────────────────────────────────────
        const rooms    = ref([]);       // Dữ liệu từ API
        const loading  = ref(false);    // Loading state danh sách
        const apiError = ref('');       // Lỗi fetch toàn trang

        // ── State: Form của từng phòng (keyed by room_id) ─────────────────
        // { [roomId]: { electricity_index, water_index, imageFile, imagePreview,
        //               saving, saved, errors } }
        const forms = reactive({});

        // ── Computed: Năm hợp lệ để chọn ────────────────────────────────
        const yearOptions = computed(() => {
            const current = new Date().getFullYear();
            return Array.from({ length: 5 }, (_, i) => current - 2 + i);
        });

        const monthOptions = computed(() =>
            Array.from({ length: 12 }, (_, i) => ({
                value: i + 1,
                label: `Tháng ${i + 1}`,
            }))
        );

        // ── Khởi tạo form state cho một phòng ────────────────────────────
        const initRoomForm = (room) => {
            const reading = room.reading;
            forms[room.id] = {
                electricity_index: reading?.electricity_index ?? '',
                water_index:       reading?.water_index       ?? '',
                imageFile:         null,
                imagePreview:      reading?.evidence_image_url ?? null,
                saving:            false,
                saved:             false,
                errors:            {},  // { electricity_index: ['...'], water_index: [...] }
            };
        };

        // ── Load danh sách phòng từ API ───────────────────────────────────
        const fetchRooms = async () => {
            loading.value  = true;
            apiError.value = '';

            try {
                const { data } = await http.get('/landlord/utility-readings/rooms', {
                    params: {
                        month: selectedMonth.value,
                        year:  selectedYear.value,
                    },
                });

                rooms.value = data.data ?? [];

                // Khởi tạo form state cho mỗi phòng
                rooms.value.forEach(initRoomForm);

            } catch (err) {
                apiError.value =
                    err.response?.data?.message
                    ?? 'Không thể tải danh sách phòng. Vui lòng thử lại.';
            } finally {
                loading.value = false;
            }
        };

        // ── Xử lý chọn file ảnh ──────────────────────────────────────────
        const onImageChange = (roomId, event) => {
            const file = event.target.files?.[0];
            if (! file) return;

            // Validate phía client trước khi upload
            const maxSize = 2 * 1024 * 1024; // 2MB
            if (file.size > maxSize) {
                forms[roomId].errors.evidence_image = ['Ảnh không được vượt quá 2MB.'];
                return;
            }
            if (! file.type.startsWith('image/')) {
                forms[roomId].errors.evidence_image = ['File phải là ảnh.'];
                return;
            }

            // Xóa lỗi cũ
            delete forms[roomId].errors.evidence_image;

            // Lưu file vào form state
            forms[roomId].imageFile = file;

            // Tạo preview URL (object URL, giải phóng khi unmount)
            forms[roomId].imagePreview = URL.createObjectURL(file);
        };

        // ── Tính sản lượng tiêu thụ (so với tháng trước) ─────────────────
        const calcConsumption = (room, type) => {
            const prev = room.previous_reading;
            if (! prev) return null;

            const current  = parseInt(forms[room.id]?.[`${type}_index`]) || 0;
            const previous = parseInt(prev[`${type}_index`]) || 0;
            const diff     = current - previous;

            if (diff < 0) return null; // Lỗi chỉ số giảm

            const price = type === 'electricity' ? room.electric_price : room.water_price;
            return {
                units:    diff,
                cost:     diff * price,
                costStr:  formatVnd(diff * price),
                unit:     type === 'electricity' ? 'kWh' : 'm³',
            };
        };

        // ── Lưu chỉ số một phòng ─────────────────────────────────────────
        const saveReading = async (room) => {
            const form = forms[room.id];
            if (! form || form.saving) return;

            form.saving = false;
            form.saved  = false;
            form.errors = {};
            form.saving = true;

            try {
                // Dùng FormData vì có upload file (multipart/form-data)
                const payload = new FormData();
                payload.append('room_id',           room.id);
                payload.append('month',             selectedMonth.value);
                payload.append('year',              selectedYear.value);
                payload.append('electricity_index', form.electricity_index);
                payload.append('water_index',       form.water_index);

                if (form.imageFile) {
                    payload.append('evidence_image', form.imageFile);
                }

                const { data } = await http.post('/landlord/utility-readings', payload, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                // Cập nhật preview với URL từ server (tránh object URL hết hạn)
                if (data.data?.evidence_image_url) {
                    form.imagePreview = data.data.evidence_image_url;
                }
                form.imageFile = null; // Xóa file sau khi upload thành công

                // Cập nhật chỉ số tháng trước trong room data
                const roomInList = rooms.value.find(r => r.id === room.id);
                if (roomInList) {
                    roomInList.reading = data.data;
                }

                form.saved = true;
                // Tắt trạng thái "đã lưu" sau 3 giây
                setTimeout(() => { form.saved = false; }, 3000);

                showToast('Lưu chỉ số thành công! ✓');

            } catch (err) {
                if (err.response?.status === 422) {
                    // Lỗi validation từ Laravel → hiển thị inline
                    form.errors = err.response.data.errors ?? {};
                } else {
                    // Lỗi khác → dùng toast
                    showToast(
                        err.response?.data?.message ?? 'Đã xảy ra lỗi khi lưu.',
                        'red'
                    );
                }
            } finally {
                form.saving = false;
            }
        };

        // ── Toast helper (dùng Toastify đã có trong layout) ──────────────
        const showToast = (msg, color = 'linear-gradient(135deg,#10b981,#059669)') => {
            if (window.Toastify) {
                window.Toastify({
                    text:      msg,
                    duration:  3000,
                    gravity:   'top',
                    position:  'right',
                    style:     { background: color, borderRadius: '10px' },
                }).showToast();
            }
        };

        // ── Lifecycle ─────────────────────────────────────────────────────
        onMounted(() => {
            fetchRooms();
        });

        return {
            // State
            selectedMonth, selectedYear,
            rooms, loading, apiError, forms,
            // Computed
            yearOptions, monthOptions,
            // Methods
            fetchRooms, onImageChange, calcConsumption, saveReading,
            formatVnd,
        };
    },

    // ── Template (inlined via template string) ────────────────────────────
    template: /* html */ `
    <div>

        {{-- ── Month/Year Picker ── --}}
        <div class="month-picker-card">
            <label>📅 Kỳ nhập liệu:</label>

            <select v-model.number="selectedMonth" id="select-month" style="width:140px">
                <option v-for="m in monthOptions" :key="m.value" :value="m.value">
                    {{ m.label }}
                </option>
            </select>

            <input
                id="input-year"
                v-model.number="selectedYear"
                type="number" min="2020" max="2099"
                style="width:90px"
                placeholder="Năm"
            />

            <button
                id="btn-load-rooms"
                class="btn-load"
                :disabled="loading"
                @click="fetchRooms"
            >
                <span v-if="loading" class="spinner"></span>
                <span v-else>🔄</span>
                {{ loading ? 'Đang tải...' : 'Tải danh sách' }}
            </button>
        </div>

        {{-- ── API Error ── --}}
        <div v-if="apiError" style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:.9rem;">
            ⚠️ {{ apiError }}
        </div>

        {{-- ── Skeleton loaders ── --}}
        <div v-if="loading" class="rooms-grid">
            <div v-for="n in 3" :key="n" class="skeleton-card">
                <div class="skeleton-line w-60"></div>
                <div class="skeleton-line w-40"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:16px">
                    <div class="skeleton-line h-32"></div>
                    <div class="skeleton-line h-32"></div>
                    <div class="skeleton-line h-32"></div>
                </div>
            </div>
        </div>

        {{-- ── Empty state ── --}}
        <div v-else-if="!loading && rooms.length === 0 && !apiError" class="empty-state">
            <div class="empty-icon">🏘️</div>
            <p>Không có phòng nào đang có hợp đồng hiệu lực.</p>
            <p style="font-size:.8rem;color:#94a3b8;margin-top:6px">
                Tạo hợp đồng trước khi nhập chỉ số điện/nước.
            </p>
        </div>

        {{-- ── Room Cards ── --}}
        <div v-else class="rooms-grid">
            <div
                v-for="room in rooms"
                :key="room.id"
                class="room-card"
            >
                {{-- Card Header --}}
                <div class="room-card-header">
                    <div>
                        <div class="room-name">
                            🏠 {{ room.name }}
                        </div>
                        <div class="room-tenant" v-if="room.tenant_name">
                            👤 {{ room.tenant_name }}
                            <span v-if="room.tenant_phone"> · 📞 {{ room.tenant_phone }}</span>
                        </div>
                    </div>
                    <div>
                        <span
                            v-if="room.reading?.status === 'finalized'"
                            class="badge-status badge-finalized"
                        >✓ Đã chốt</span>
                        <span
                            v-else-if="room.reading?.status === 'draft'"
                            class="badge-status badge-draft"
                        >📝 Nháp</span>
                        <span v-else class="badge-status badge-empty">Chưa nhập</span>
                    </div>
                </div>

                {{-- Card Body: 3-column grid --}}
                <div class="room-card-body" v-if="forms[room.id]">

                    {{-- ── Cột 1: Điện ── --}}
                    <div class="input-group-billing">
                        <label>
                            <span class="meter-icon">⚡</span>
                            Chỉ số điện (kWh)
                        </label>
                        <div class="input-with-ref">
                            <input
                                :id="'electricity-' + room.id"
                                v-model.number="forms[room.id].electricity_index"
                                type="number" min="0"
                                class="meter-input"
                                :class="{
                                    'is-error': forms[room.id].errors?.electricity_index?.length,
                                    'is-saved': forms[room.id].saved
                                }"
                                placeholder="Nhập chỉ số..."
                                :disabled="room.reading?.status === 'finalized'"
                            />
                        </div>
                        <div
                            v-if="forms[room.id].errors?.electricity_index"
                            class="field-error"
                        >⚠ {{ forms[room.id].errors.electricity_index[0] }}</div>
                        <div class="ref-hint" v-if="room.previous_reading">
                            Tháng trước: {{ room.previous_reading.electricity_index }} kWh
                            <template v-if="calcConsumption(room, 'electricity') as c && c">
                                · Tiêu thụ:
                                <span class="consumption">{{ calcConsumption(room, 'electricity')?.units }} kWh</span>
                                ≈ {{ calcConsumption(room, 'electricity')?.costStr }}
                            </template>
                        </div>
                        <div class="ref-hint" style="color:#94a3b8" v-else>
                            (Chưa có dữ liệu tháng trước)
                        </div>
                        <div class="ref-hint">
                            Đơn giá: {{ formatVnd(room.electric_price) }}/kWh
                        </div>
                    </div>

                    {{-- ── Cột 2: Nước ── --}}
                    <div class="input-group-billing">
                        <label>
                            <span class="meter-icon">💧</span>
                            Chỉ số nước (m³)
                        </label>
                        <div class="input-with-ref">
                            <input
                                :id="'water-' + room.id"
                                v-model.number="forms[room.id].water_index"
                                type="number" min="0"
                                class="meter-input"
                                :class="{
                                    'is-error': forms[room.id].errors?.water_index?.length,
                                    'is-saved': forms[room.id].saved
                                }"
                                placeholder="Nhập chỉ số..."
                                :disabled="room.reading?.status === 'finalized'"
                            />
                        </div>
                        <div
                            v-if="forms[room.id].errors?.water_index"
                            class="field-error"
                        >⚠ {{ forms[room.id].errors.water_index[0] }}</div>
                        <div class="ref-hint" v-if="room.previous_reading">
                            Tháng trước: {{ room.previous_reading.water_index }} m³
                            <template v-if="calcConsumption(room, 'water') as c && c">
                                · Tiêu thụ:
                                <span class="consumption">{{ calcConsumption(room, 'water')?.units }} m³</span>
                                ≈ {{ calcConsumption(room, 'water')?.costStr }}
                            </template>
                        </div>
                        <div class="ref-hint" style="color:#94a3b8" v-else>
                            (Chưa có dữ liệu tháng trước)
                        </div>
                        <div class="ref-hint">
                            Đơn giá: {{ formatVnd(room.water_price) }}/m³
                        </div>
                    </div>

                    {{-- ── Cột 3: Ảnh chứng minh ── --}}
                    <div class="input-group-billing">
                        <label>📷 Ảnh chụp đồng hồ</label>
                        <div
                            class="upload-zone"
                            :class="{ 'is-error': forms[room.id].errors?.evidence_image?.length }"
                        >
                            <input
                                :id="'image-' + room.id"
                                type="file"
                                accept="image/jpeg,image/jpg,image/png,image/webp"
                                @change="onImageChange(room.id, $event)"
                                :disabled="room.reading?.status === 'finalized'"
                            />

                            <template v-if="forms[room.id].imagePreview">
                                <img
                                    :src="forms[room.id].imagePreview"
                                    class="preview-img"
                                    alt="Ảnh đồng hồ"
                                />
                                <span class="upload-hint">Bấm để thay ảnh</span>
                            </template>
                            <template v-else>
                                <span style="font-size:1.8rem">📸</span>
                                <span class="upload-hint">JPG/PNG/WEBP · Tối đa 2MB</span>
                            </template>
                        </div>
                        <div
                            v-if="forms[room.id].errors?.evidence_image"
                            class="field-error"
                        >⚠ {{ forms[room.id].errors.evidence_image[0] }}</div>
                    </div>
                </div>

                {{-- ── Save Row ── --}}
                <div class="btn-save-row" v-if="forms[room.id]">
                    <transition name="fade">
                        <span v-if="forms[room.id].saved" class="save-success-msg">
                            ✅ Đã lưu thành công!
                        </span>
                    </transition>
                    <button
                        :id="'btn-save-' + room.id"
                        class="btn-save"
                        :disabled="forms[room.id].saving || room.reading?.status === 'finalized'"
                        @click="saveReading(room)"
                    >
                        <span v-if="forms[room.id].saving" class="spinner"></span>
                        <span v-else>💾</span>
                        {{ forms[room.id].saving ? 'Đang lưu...' : 'Lưu chỉ số' }}
                    </button>
                </div>

            </div>
        </div>

    </div>
    `,
};
