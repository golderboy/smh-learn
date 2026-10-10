export class LearningHttpError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
    }
}
const statusMessages: Record<number, string> = {
    401: 'การเข้าสู่ระบบหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง',
    403: 'คุณไม่มีสิทธิ์ทำรายการนี้ หรือการเรียนไม่อยู่ในช่วงที่เปิดใช้',
    404: 'ไม่พบรายการนี้ กรุณาตรวจสอบหน้าล่าสุดจากระบบ',
    409: 'ข้อมูลเปลี่ยนจากอีกหน้าหรืออุปกรณ์ กรุณาโหลดสถานะล่าสุดก่อนทำต่อ',
    419: 'การเข้าสู่ระบบหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง',
    429: 'มีการส่งรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่',
    500: 'ระบบบันทึกไม่สำเร็จ กรุณาลองใหม่ภายหลัง',
    503: 'ระบบยังไม่พร้อมให้บริการ กรุณาลองใหม่ภายหลัง',
};
export async function learningPost<T>(url: string, data: Record<string, unknown>): Promise<T> {
    // Laravel rotates the token during login/password changes; the cookie stays current during Inertia navigation.
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const csrfHeaders: Record<string, string> = cookie
        ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }
        : {
              'X-CSRF-TOKEN':
                  document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          };
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...csrfHeaders,
        },
        body: JSON.stringify(data),
    }).catch(() => {
        throw new LearningHttpError(
            'เชื่อมต่อระบบไม่สำเร็จ กรุณาตรวจเครือข่ายแล้วลองบันทึกอีกครั้ง',
            0,
        );
    });
    const body = await response.json().catch(() => null);
    if (!response.ok)
        throw new LearningHttpError(
            ([401, 419].includes(response.status) || response.status >= 500
                ? statusMessages[response.status]
                : (body?.message ?? statusMessages[response.status])) ??
                'บันทึกไม่สำเร็จ กรุณาลองอีกครั้ง',
            response.status,
        );
    if (body === null)
        throw new LearningHttpError(
            'ระบบส่งข้อมูลกลับไม่สมบูรณ์ กรุณาตรวจสถานะล่าสุดก่อนทำต่อ',
            response.status,
        );
    return body as T;
}
