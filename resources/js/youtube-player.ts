export interface YoutubePlayer {
    playVideo(): void;
    pauseVideo(): void;
    seekTo(seconds: number, allowSeekAhead: boolean): void;
    getCurrentTime(): number;
    getDuration(): number;
    getPlayerState(): number;
    getPlaybackRate(): number;
    setPlaybackRate(rate: number): void;
    getVideoUrl(): string;
    mute(): void;
    unMute(): void;
    setVolume(volume: number): void;
    destroy(): void;
}
interface YoutubeApi {
    Player: new (
        element: HTMLElement,
        options: {
            videoId: string;
            width: string;
            height: string;
            playerVars: Record<string, string | number>;
            events: {
                onReady(event: { target: YoutubePlayer }): void;
                onStateChange(event: { data: number }): void;
                onError(event: { data: number }): void;
            };
        },
    ) => YoutubePlayer;
}
declare global {
    interface Window {
        YT?: YoutubeApi;
        onYouTubeIframeAPIReady?: () => void;
    }
}
let pending: Promise<YoutubeApi> | undefined;
export function loadYoutube(): Promise<YoutubeApi> {
    if (window.YT?.Player) return Promise.resolve(window.YT);
    if (pending) return pending;
    pending = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const previous = window.onYouTubeIframeAPIReady;
        const timeout = setTimeout(() => fail(), 20000);
        const fail = () => {
            clearTimeout(timeout);
            script.remove();
            pending = undefined;
            reject(new Error('โหลดตัวเล่น YouTube ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วเปิดบทใหม่'));
        };
        window.onYouTubeIframeAPIReady = () => {
            clearTimeout(timeout);
            previous?.();
            if (window.YT?.Player) resolve(window.YT);
            else fail();
        };
        script.src = 'https://www.youtube.com/iframe_api';
        script.onerror = fail;
        document.head.append(script);
    });
    return pending;
}
