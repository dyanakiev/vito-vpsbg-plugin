if command -v cloud-init >/dev/null 2>&1; then
    __vpsbg_state=$(sudo cloud-init status 2>/dev/null | awk '/^status:/ {$1=""; sub(/^ /, ""); print; exit}')
    case "$__vpsbg_state" in
        done|disabled) ;;
        error)
            echo VITO_VPSBG_BOOT_ERROR
            exit 0
            ;;
        *)
            echo VITO_VPSBG_BOOT_PENDING
            exit 0
            ;;
    esac
fi

if command -v fuser >/dev/null 2>&1 && sudo fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1; then
    echo VITO_VPSBG_BOOT_PENDING
    exit 0
fi

echo VITO_VPSBG_BOOT_READY
