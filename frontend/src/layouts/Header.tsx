import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Menu, LogOut, Package } from 'lucide-react'
import { useAuth } from '@/app/AuthContext'
import { useBrandingLogoObjectUrl, useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { SidebarNav } from './SidebarNav'

function initialsOf(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

export function Header() {
  const { user, logout } = useAuth()
  const { data: branding } = useCompanyBranding()
  const logoObjectUrl = useBrandingLogoObjectUrl(branding?.logo_url)
  const navigate = useNavigate()
  const [isMobileNavOpen, setIsMobileNavOpen] = useState(false)

  useEffect(() => {
    document.title = branding?.name ?? 'ERP'
  }, [branding?.name])

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <header className="flex h-14 items-center gap-3 border-b bg-card px-4">
      <Sheet open={isMobileNavOpen} onOpenChange={setIsMobileNavOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="md:hidden">
            <Menu className="size-5" />
            <span className="sr-only">Toggle menu</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="w-64 p-0">
          <SheetHeader className="flex h-14 flex-row items-center gap-2 border-b px-4">
            {logoObjectUrl ? (
              <img src={logoObjectUrl} alt="" className="size-5 shrink-0 object-contain" />
            ) : (
              <Package className="size-5 shrink-0 text-primary" />
            )}
            <SheetTitle className="text-base">{branding?.name ?? 'Loading…'}</SheetTitle>
          </SheetHeader>
          <SidebarNav onNavigate={() => setIsMobileNavOpen(false)} />
        </SheetContent>
      </Sheet>

      <div className="flex-1" />

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" className="flex items-center gap-2 px-2">
            <Avatar className="size-7">
              <AvatarFallback className="text-xs">{user ? initialsOf(user.name) : '?'}</AvatarFallback>
            </Avatar>
            <span className="hidden text-sm font-medium sm:inline">{user?.name}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuLabel>
            <div className="flex flex-col">
              <span className="font-medium">{user?.name}</span>
              <span className="text-xs font-normal text-muted-foreground">{user?.email}</span>
            </div>
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuItem onClick={handleLogout}>
            <LogOut className="size-4" />
            Log out
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </header>
  )
}
