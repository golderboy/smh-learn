<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { loadYoutube, type YoutubePlayer } from '../youtube-player';
import { learningPost } from '../learning-http';
import type { ContentRecord, EnrollmentRecord, ProgressRecord } from '../Types/learning';
const props = defineProps<{
    content: ContentRecord;
    enrollmentId: number;
    progress?: ProgressRecord;
    threshold: string;
}>();
const emit = defineEmits<{ saved: [progress: ProgressRecord, enrollment: EnrollmentRecord] }>();
const video = ref<HTMLVideoElement>();
const youtubeHost = ref<HTMLDivElement>();
const youtubeReady = ref(false);
let youtube: YoutubePlayer | undefined;
let disposed = false;
let pollTimer: ReturnType<typeof setInterval> | undefined;
let lastPoll = performance.now();
let initialized = false;
let awaitingResume = false;
let youtubeEnded = false;
let invalidSource = false;
const error = ref('');
const busy = ref(false);
const key = ref<string | null>(null);
const savedPercent = ref(props.progress?.watch_percent ?? '0');
const player = ref<HTMLDivElement>();
const playing = ref(false);
const muted = ref(false);
const volume = ref(1);
const position = ref(0);
const duration = ref(0);
let allowedPosition = Number(props.progress?.last_position_seconds ?? 0);
let timer: ReturnType<typeof setInterval> | undefined;
let resumeLoaded = false;
let needBegin = false;
let needSave = false;
let preparingPlayback = false;
let preparedPlayback = false;
const saveWaiters: (() => void)[] = [];
const currentTime = () => youtube?.getCurrentTime() ?? video.value?.currentTime ?? 0;
const currentDuration = () => youtube?.getDuration() ?? video.value?.duration ?? 0;
function pause() {
    youtube?.pauseVideo();
    video.value?.pause();
}
function checkDuration(): boolean {
    const actual = currentDuration();
    if (!Number.isFinite(actual) || actual <= 0) return false;
    if (
        props.content.video_source &&
        props.content.video_source !== 'upload' &&
        Math.ceil(actual) !== props.content.duration_seconds
    ) {
        invalidSource = true;
        error.value = 'ความยาววิดีโอไม่ตรงกับที่กำหนด กรุณาแจ้ง Instructor ตรวจสอบลิงก์และความยาว';
        pause();
        return false;
    }
    duration.value = actual;
    return true;
}
async function send(begin: boolean) {
    if ((!video.value && !youtube) || invalidSource) return;
    if (busy.value) {
        if (begin) needBegin = true;
        else needSave = true;
        return;
    }
    if (!begin && !key.value) return;
    busy.value = true;
    try {
        const result = await learningPost<{
            key: string;
            progress: ProgressRecord;
            enrollment: EnrollmentRecord;
        }>(`/learning/${props.enrollmentId}/contents/${props.content.id}/heartbeat`, {
            begin,
            key: key.value,
            position: currentTime(),
            duration: currentDuration(),
            ended: youtube ? youtubeEnded : video.value?.ended === true,
        });
        key.value = result.key;
        savedPercent.value = result.progress.watch_percent;
        error.value = '';
        emit('saved', result.progress, result.enrollment);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'บันทึกการรับชมไม่สำเร็จ';
        key.value = null;
        pause();
    } finally {
        busy.value = false;
        saveWaiters.splice(0).forEach((resolve) => resolve());
        if (needBegin) {
            needBegin = false;
            void send(true);
        } else if (needSave) {
            needSave = false;
            void send(false);
        }
    }
}
function loaded() {
    if (!checkDuration()) return;
    if (!resumeLoaded && video.value) {
        video.value.currentTime = Math.min(
            Number(props.progress?.last_position_seconds ?? 0),
            video.value.duration,
        );
        resumeLoaded = true;
        allowedPosition = video.value.currentTime;
        position.value = allowedPosition;
        duration.value = video.value.duration;
    }
}
async function play() {
    if (!checkDuration()) return;
    playing.value = true;
    if (preparedPlayback) {
        preparedPlayback = false;
        return;
    }
    if (preparingPlayback) return;
    if (
        (youtube?.getPlaybackRate() ?? video.value?.playbackRate) === 1 &&
        document.visibilityState === 'visible'
    ) {
        // Start the server clock before media advances. Otherwise request latency
        // permanently loses the first segment of a short clip at a 100% threshold.
        preparingPlayback = true;
        pause();
        try {
            while (busy.value) await new Promise<void>((resolve) => saveWaiters.push(resolve));
            if (disposed) return;
            await send(true);
            if (disposed || !key.value) return;
            preparedPlayback = true;
            youtube?.playVideo();
            if (video.value) await video.value.play();
        } catch {
            preparedPlayback = false;
            error.value = 'เล่นวิดีโอไม่ได้ กรุณาลองใหม่';
        } finally {
            preparingPlayback = false;
        }
    }
}
function paused() {
    playing.value = false;
    if (!preparingPlayback) void send(false);
}
function track() {
    if (video.value && !video.value.seeking) {
        allowedPosition = video.value.currentTime;
        position.value = allowedPosition;
    }
}
function preventSeek() {
    if (video.value && Math.abs(video.value.currentTime - allowedPosition) > 0.25)
        video.value.currentTime = allowedPosition;
}
async function toggle() {
    if (youtube) {
        if (youtube.getPlayerState() === 1) youtube.pauseVideo();
        else if (!invalidSource) youtube.playVideo();
        return;
    }
    if (!video.value) return;
    if (video.value.paused) {
        if (video.value.ended) {
            allowedPosition = 0;
            video.value.currentTime = 0;
        }
        try {
            await video.value.play();
        } catch {
            error.value = 'เล่นวิดีโอไม่ได้ กรุณาลองใหม่';
        }
    } else video.value.pause();
}
function restart() {
    if (!props.progress?.completed_at) return;
    key.value = null;
    pause();
    allowedPosition = 0;
    position.value = 0;
    if (youtube) youtube.seekTo(0, true);
    if (video.value) video.value.currentTime = 0;
}
async function fullscreen() {
    try {
        await player.value?.requestFullscreen();
    } catch {
        error.value = 'อุปกรณ์นี้ไม่รองรับการแสดงเต็มจอ';
    }
}
const clock = (seconds: number) =>
    `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
function visibility() {
    if (document.visibilityState === 'hidden') {
        pause();
    } else if (video.value && !video.value.paused) play();
}
function youtubeState(state: number) {
    playing.value = state === 1;
    if (state === 1) {
        youtubeEnded = false;
        if (!checkDuration()) return;
        if (!initialized) {
            initialized = true;
            const resume = Number(props.progress?.last_position_seconds ?? 0);
            allowedPosition = resume;
            if (resume > 0) youtube?.seekTo(resume, true);
            awaitingResume = resume > 0;
            lastPoll = performance.now();
        }
        if (!awaitingResume) void play();
    } else if (state === 2 || state === 0) {
        if (state === 0) youtubeEnded = true;
        if (!preparingPlayback) void send(false);
    }
}
async function initializeYoutube() {
    try {
        const api = await loadYoutube();
        if (disposed || !youtubeHost.value || !props.content.video_id) return;
        youtube = new api.Player(youtubeHost.value, {
            videoId: props.content.video_id,
            width: '100%',
            height: '100%',
            playerVars: {
                controls: 0,
                disablekb: 1,
                fs: 0,
                playsinline: 1,
                enablejsapi: 1,
                origin: window.location.origin,
                rel: 0,
            },
            events: {
                onReady: (event) => {
                    youtube = event.target;
                    youtubeReady.value = true;
                },
                onStateChange: (event) => youtubeState(event.data),
                onError: (event) => {
                    error.value = `YouTube เปิดคลิปนี้ไม่ได้ (รหัส ${event.data}) กรุณาตรวจสอบสิทธิ์การฝังและเครือข่าย`;
                    invalidSource = true;
                    playing.value = false;
                },
            },
        });
        pollTimer = setInterval(() => {
            if (!youtube || !initialized || invalidSource) return;
            const actual = youtube.getCurrentTime();
            const now = performance.now();
            const elapsed = (now - lastPoll) / 1000;
            lastPoll = now;
            if (awaitingResume) {
                if (Math.abs(actual - allowedPosition) <= 0.75) {
                    awaitingResume = false;
                    play();
                }
                return;
            }
            if (youtube.getVideoUrl().split('v=')[1]?.split('&')[0] !== props.content.video_id) {
                invalidSource = true;
                pause();
                error.value = 'คลิปที่เล่นไม่ตรงกับบทเรียน กรุณาเปิดบทใหม่';
                return;
            }
            if (youtube.getPlaybackRate() !== 1) {
                youtube.setPlaybackRate(1);
                pause();
                return;
            }
            if (actual < allowedPosition - 0.5 || actual > allowedPosition + elapsed + 0.75) {
                youtube.seekTo(allowedPosition, true);
            } else {
                allowedPosition = actual;
                position.value = actual;
            }
        }, 250);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'เปิด YouTube ไม่ได้';
    }
}
function toggleMuted() {
    muted.value = !muted.value;
    if (youtube) {
        if (muted.value) youtube.mute();
        else youtube.unMute();
    }
    if (video.value) video.value.muted = muted.value;
}
function setVolume() {
    youtube?.setVolume(Math.round(volume.value * 100));
    if (video.value) video.value.volume = volume.value;
}
onMounted(() => {
    if (props.content.video_source === 'youtube') void initializeYoutube();
    timer = setInterval(() => {
        if (
            ((youtube?.getPlayerState() === 1 && youtube.getPlaybackRate() === 1) ||
                (video.value &&
                    !video.value.paused &&
                    !video.value.seeking &&
                    video.value.playbackRate === 1)) &&
            document.visibilityState === 'visible'
        )
            void send(!key.value);
    }, 5000);
    document.addEventListener('visibilitychange', visibility);
});
onBeforeUnmount(() => {
    disposed = true;
    pause();
    youtube?.destroy();
    clearInterval(pollTimer);
    clearInterval(timer);
    document.removeEventListener('visibilitychange', visibility);
});
</script>
<template>
    <div ref="player" class="learning-video">
        <div
            v-if="content.video_source === 'youtube'"
            class="youtube-frame"
            :data-ready="youtubeReady"
        >
            <div ref="youtubeHost"></div>
        </div>
        <video
            v-else
            ref="video"
            preload="metadata"
            playsinline
            :src="
                content.video_source === 'remote'
                    ? (content.external_url ?? undefined)
                    : `/media/${content.id}?enrollment=${enrollmentId}`
            "
            :aria-label="content.title"
            @loadedmetadata="loaded"
            @play="play"
            @pause="paused"
            @ended="send(false)"
            @seeking="preventSeek"
            @timeupdate="track"
            @ratechange="video && (video.playbackRate = 1)"
            @error="error = 'เปิดวิดีโอไม่ได้ กรุณาตรวจสอบไฟล์ ลิงก์ สิทธิ์การเข้าถึงและเครือข่าย'"
        ></video>
        <div class="video-controls">
            <button class="btn btn-primary" @click="toggle">
                {{ playing ? 'หยุดชั่วคราว' : 'เล่นวิดีโอ' }}</button
            ><span class="identifier">{{ clock(position) }} / {{ clock(duration) }}</span
            ><button class="btn btn-secondary" @click="toggleMuted">
                {{ muted ? 'เปิดเสียง' : 'ปิดเสียง' }}</button
            ><label class="video-volume"
                >ระดับเสียง
                <input
                    v-model.number="volume"
                    type="range"
                    min="0"
                    max="1"
                    step="0.1"
                    @input="setVolume" /></label
            ><button class="btn btn-secondary" @click="fullscreen">เต็มจอ</button>
            <button v-if="progress?.completed_at" class="btn btn-secondary" @click="restart">
                ดูซ้ำตั้งแต่ต้น
            </button>
        </div>
        <div class="section-heading">
            <span>รับชมแล้ว {{ Number(savedPercent).toFixed(1) }}% · เกณฑ์ {{ threshold }}%</span
            ><span v-if="busy" class="muted" role="status">กำลังบันทึก</span>
        </div>
        <progress
            :value="Number(savedPercent)"
            max="100"
            aria-label="ความก้าวหน้าการรับชม"
        ></progress>
        <p class="muted">
            รับชมด้วยความเร็วปกติ เลื่อนข้ามวิดีโอไม่ได้
            ระบบกลับมาตำแหน่งล่าสุดเมื่อเปิดบทเรียนอีกครั้ง
        </p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    </div>
</template>
